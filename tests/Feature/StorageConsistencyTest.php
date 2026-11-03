<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use Symfony\Component\Process\Process;

it('rejects separately registered resources inside a directory version before writing', function () {
    $parent = VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        $files->put('summary.txt', 'parent contents');
    });
    $childPath = "reports/{$parent}/independent";
    expect(fn () => VersionedStorage::put($childPath, 'independent contents'))
        ->toThrow(InvalidArgumentException::class, 'overlaps registered resource');
    Storage::disk('local')->assertMissing($childPath);

    $replacement = VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        $files->put('summary.txt', 'replacement contents');
    }, true);

    expect(VersionedStorage::directory('reports')->versions())->toBe([$replacement])
        ->and(VersionedStorage::directory('reports')->get('summary.txt'))->toBe('replacement contents')
        ->and(VersionedStorage::versions($childPath))->toBe([])
        ->and(VersionedStorage::exists($childPath))->toBeFalse();
    Storage::disk('local')->assertMissing("reports/{$parent}/summary.txt");
});

it('rejects overlapping resources registered through a second disk name for the same filesystem', function () {
    $local = Storage::disk('local');
    Storage::set('local-alias', $local);

    try {
        $parent = VersionedStorage::directory('shared-reports', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'parent contents');
        }, disk: 'local');
        $childPath = "shared-reports/{$parent}/independent";
        expect(fn () => VersionedStorage::put($childPath, 'independent contents', disk: 'local-alias'))
            ->toThrow(InvalidArgumentException::class, 'overlaps registered resource');

        $replacement = VersionedStorage::directory('shared-reports', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'replacement contents');
        }, cleanup: true, disk: 'local');

        expect(VersionedStorage::directory('shared-reports', disk: 'local')->versions())->toBe([$replacement])
            ->and(VersionedStorage::versions($childPath, 'local-alias'))->toBe([])
            ->and(VersionedStorage::exists($childPath, disk: 'local-alias'))->toBeFalse();
        $local->assertMissing("shared-reports/{$parent}/summary.txt");
        $local->assertMissing($childPath);
    } finally {
        Storage::forgetDisk('local-alias');
    }
});

it('rejects the same resource root registered through a disk alias', function (bool $directory) {
    $local = Storage::disk('local');
    Storage::set('local-alias', $local);
    $called = false;
    $callback = function (FilesystemAdapter $files) use (&$called): void {
        $called = true;
        $files->put('child', 'contents');
    };

    try {
        $version = $directory
            ? VersionedStorage::directory('shared', $callback)
            : VersionedStorage::put('shared', 'contents');
        $called = false;

        expect(fn () => $directory
            ? VersionedStorage::directory('shared', $callback, disk: 'local-alias')
            : VersionedStorage::put('shared', 'replacement', disk: 'local-alias'))
            ->toThrow(InvalidArgumentException::class, 'overlaps registered resource');

        expect($called)->toBeFalse()
            ->and($directory
                ? VersionedStorage::directory('shared')->versions()
                : VersionedStorage::versions('shared'))->toBe([$version])
            ->and($directory
                ? VersionedStorage::directory('shared', disk: 'local-alias')->versions()
                : VersionedStorage::versions('shared', 'local-alias'))->toBe([])
            ->and($local->allFiles('shared'))->toBe([
                $directory ? "shared/{$version}/child" : "shared/{$version}",
            ]);
    } finally {
        Storage::forgetDisk('local-alias');
    }
})->with([true, false]);

it('protects legacy overlapping resources registered through a second disk name during cleanup', function () {
    $local = Storage::disk('local');
    Storage::set('local-alias', $local);

    try {
        $parent = VersionedStorage::directory('shared-legacy', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'parent contents');
        }, disk: 'local');
        $childPath = "shared-legacy/{$parent}/independent";
        $child = (string) Str::uuid();
        $resourceId = DB::table('versioned_storage_resources')->insertGetId([
            'key' => hash('sha256', "local-alias\0{$childPath}\0file"),
            'disk' => 'local-alias',
            'path' => $childPath,
            'is_directory' => false,
        ]);
        DB::table('versioned_storage_versions')->insert([
            'resource_id' => $resourceId,
            'version' => $child,
            'created_at' => now(),
        ]);
        $local->put("{$childPath}/{$child}", 'independent contents');

        expect(fn () => VersionedStorage::directory('shared-legacy', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'replacement contents');
        }, cleanup: true, disk: 'local'))
            ->toThrow(RuntimeException::class, 'contains registered resource');

        expect(VersionedStorage::directory('shared-legacy', disk: 'local')->get('summary.txt'))
            ->toBe('replacement contents')
            ->and(VersionedStorage::versions($childPath, 'local-alias'))->toBe([$child])
            ->and(VersionedStorage::get($childPath, $child, 'local-alias'))->toBe('independent contents');
        $local->assertExists("shared-legacy/{$parent}/summary.txt");
        $local->assertExists("{$childPath}/{$child}");
    } finally {
        Storage::forgetDisk('local-alias');
    }
});

it('rejects nested resource roots in either registration order', function (bool $parentFirst) {
    $existing = $parentFirst ? 'reports' : 'reports/monthly';
    $rejected = $parentFirst ? 'reports/monthly' : 'reports';
    $version = VersionedStorage::put($existing, 'original');
    $called = false;
    $callback = function () use (&$called): void {
        $called = true;
    };

    expect(fn () => VersionedStorage::directory($rejected, $callback))
        ->toThrow(InvalidArgumentException::class, 'overlaps registered resource');

    expect($called)->toBeFalse()
        ->and(VersionedStorage::versions($existing))->toBe([$version])
        ->and(VersionedStorage::get($existing))->toBe('original')
        ->and(VersionedStorage::directory($rejected)->versions())->toBe([]);
})->with([true, false]);

it('allows UUIDs in resource roots and arbitrary nested snapshot contents', function () {
    $path = 'accounts/3d596509-8191-4f2c-84d4-e3a8b1acfc39/reports';
    $version = VersionedStorage::directory($path, function (FilesystemAdapter $files): void {
        $files->put('nested/3d596509-8191-4f2c-84d4-e3a8b1acfc39/report.txt', 'contents');
    });

    expect(VersionedStorage::directory($path)->get('nested/3d596509-8191-4f2c-84d4-e3a8b1acfc39/report.txt', $version))
        ->toBe('contents');
});

it('rejects a root inside an unpublished snapshot without invoking its callback', function () {
    VersionedStorage::directory('staging', function (FilesystemAdapter $files): void {
        $files->put('one', 'contents');
        $version = basename($files->path(''));

        expect(fn () => VersionedStorage::put("staging/{$version}/nested", 'unrelated'))
            ->toThrow(InvalidArgumentException::class, 'overlaps registered resource');
        expect($files->allFiles())->toBe(['one']);
    });

    expect(VersionedStorage::directory('staging')->get('one'))->toBe('contents');
});

it('refuses destructive cleanup of overlapping resources registered before the ownership rule', function () {
    $parentVersion = (string) Str::uuid();
    $childPath = "legacy/{$parentVersion}/independent";
    $child = VersionedStorage::put($childPath, 'independent contents');
    $resource = DB::table('versioned_storage_resources')->insertGetId([
        'key' => hash('sha256', "local\0legacy\0directory"),
        'disk' => 'local',
        'path' => 'legacy',
        'is_directory' => true,
        'current_version' => $parentVersion,
    ]);
    DB::table('versioned_storage_versions')->insert([
        'resource_id' => $resource,
        'version' => $parentVersion,
        'created_at' => now(),
    ]);
    Storage::disk('local')->put("legacy/{$parentVersion}/one", 'old contents');

    expect(fn () => VersionedStorage::directory('legacy', function (FilesystemAdapter $files): void {
        $files->put('one', 'replacement');
    }, true))->toThrow(RuntimeException::class, 'contains registered resource');

    expect(VersionedStorage::get($childPath, $child))->toBe('independent contents')
        ->and(VersionedStorage::directory('legacy')->get('one'))->toBe('replacement')
        ->and(DB::table('versioned_storage_versions')->where('version', $parentVersion)->value('pending_delete'))->toBe(1);
    Storage::disk('local')->assertExists("legacy/{$parentVersion}/one");
});

it('serializes conflicting first registrations from separate processes', function (bool $parentFirst) {
    $database = tempnam(sys_get_temp_dir(), 'versioned-storage-roots-');
    config()->set('database.connections.ownership', [
        'driver' => 'sqlite',
        'database' => $database,
        'foreign_key_constraints' => true,
        'busy_timeout' => 5000,
    ]);
    config()->set('laravel-versioned-storage.connection', 'ownership');
    $process = null;
    $connection = DB::connection('ownership');

    try {
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $path) {
            (require $path)->up();
        }

        $existing = $parentFirst ? 'reports' : 'reports/monthly';
        $rejected = $parentFirst ? 'reports/monthly' : 'reports';
        $connection->beginTransaction();
        $version = VersionedStorage::put($existing, 'original');
        $disk = Storage::disk('local');
        $process = new Process([
            PHP_BINARY, __DIR__.'/../Support/cleanup-worker.php',
            $database, $disk->path(''), 'register', $rejected,
        ]);
        $process->setTimeout(10);
        $process->start();

        expect($process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'registering')))
            ->toBeTrue($process->getErrorOutput().$process->getOutput());
        usleep(100000);
        expect($process->isRunning())->toBeTrue($process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())->not->toContain('finished');

        $connection->commit();

        expect($process->wait())->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())->toContain('rejected:')->not->toContain("accepted\n")
            ->and(VersionedStorage::get($existing))->toBe('original')
            ->and($disk->allFiles())->toBe(["{$existing}/{$version}"])
            ->and($connection->table('versioned_storage_resources')->count())->toBe(1);
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }

        $process?->stop();
        config()->set('laravel-versioned-storage.connection', null);
        DB::purge('ownership');
        unlink($database);
    }
})->with([true, false]);

it('rolls staged files back on the disk that received the write even after its name is rebound', function () {
    $original = Storage::disk('local');
    $replacement = Storage::fake('replacement');
    DB::beginTransaction();
    $version = VersionedStorage::put('rebound', 'staged');
    $replacement->put("rebound/{$version}", 'unrelated');
    Storage::set('local', $replacement);
    DB::rollBack();

    expect($original->exists("rebound/{$version}"))->toBeFalse()
        ->and($replacement->get("rebound/{$version}"))->toBe('unrelated');
});

it('lists authoritative versions rather than stale read replica metadata', function () {
    $connection = DB::connection();
    $replica = new PDO('sqlite::memory:');
    $replica->exec('CREATE TABLE versioned_storage_resources (id INTEGER, key TEXT)');
    $replica->exec('CREATE TABLE versioned_storage_versions (id INTEGER, resource_id INTEGER, version TEXT, pending_delete INTEGER)');
    $connection->setReadPdo($replica);

    try {
        $version = VersionedStorage::put('replica', 'contents');
        expect(VersionedStorage::versions('replica'))->toBe([$version]);
    } finally {
        $connection->setReadPdo($connection->getPdo());
    }
});

it('cleans the original disk after commit even if the configured disk name is rebound', function () {
    $original = Storage::disk('local');
    $first = VersionedStorage::put('rebound-commit', 'original');
    $replacement = Storage::fake('replacement');
    $replacement->put("rebound-commit/{$first}", 'unrelated');
    DB::beginTransaction();
    $second = VersionedStorage::put('rebound-commit', 'replacement', true);
    Storage::set('local', $replacement);
    DB::commit();

    expect($original->exists("rebound-commit/{$first}"))->toBeFalse()
        ->and($original->get("rebound-commit/{$second}"))->toBe('replacement')
        ->and($replacement->get("rebound-commit/{$first}"))->toBe('unrelated');
});

it('discovers and retries pending deletions on the primary despite replica lag', function () {
    $first = VersionedStorage::put('retry-replica', 'original');
    $second = VersionedStorage::put('retry-replica', 'replacement');
    DB::table('versioned_storage_versions')->where('version', $first)->update(['pending_delete' => true]);
    $connection = DB::connection();
    $replica = new PDO('sqlite::memory:');
    $replica->exec('CREATE TABLE versioned_storage_resources (id INTEGER, key TEXT)');
    $replica->exec('CREATE TABLE versioned_storage_versions (id INTEGER, resource_id INTEGER, version TEXT, pending_delete INTEGER)');
    $connection->setReadPdo($replica);

    try {
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        Storage::disk('local')->assertMissing("retry-replica/{$first}");
        expect(VersionedStorage::versions('retry-replica'))->toBe([$second]);
    } finally {
        $connection->setReadPdo($connection->getPdo());
    }
});

it('does not delete committed data based on a stale replica after a commit listener throws', function () {
    VersionedStorage::put('replica-failure', 'original');
    $connection = DB::connection();
    $replica = new PDO('sqlite::memory:');
    $replica->exec('CREATE TABLE versioned_storage_resources (id INTEGER, key TEXT)');
    $replica->exec('CREATE TABLE versioned_storage_versions (id INTEGER, resource_id INTEGER, version TEXT, pending_delete INTEGER)');
    $connection->setReadPdo($replica);
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection->transactionLevel() === 0) {
            throw new RuntimeException('post-commit failure');
        }
    });

    try {
        expect(fn () => VersionedStorage::put('replica-failure', 'committed'))
            ->toThrow(RuntimeException::class, 'post-commit failure');
    } finally {
        Event::forget(TransactionCommitted::class);
        $connection->setReadPdo($connection->getPdo());
    }

    expect(VersionedStorage::get('replica-failure'))->toBe('committed');
});
