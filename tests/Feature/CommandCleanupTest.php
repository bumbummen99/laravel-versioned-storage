<?php

declare(strict_types=1);

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use SkyRaptor\LaravelVersionedStorage\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Process\Process;

it('leaves retired snapshots for the command after HTTP or console termination', function (bool $console) {
    $first = VersionedStorage::put('reports', 'original');
    $second = VersionedStorage::put('reports', 'replacement', cleanup: true);

    expect(VersionedStorage::versions('reports'))->toBe([$second])
        ->and(VersionedStorage::exists('reports', $first))->toBeFalse();

    if ($console) {
        app(ConsoleKernel::class)->terminate(new ArrayInput([]), 0);
    } else {
        app(HttpKernel::class)->terminate(Request::create('/'), new Response('sent'));
    }

    VersionedStorage::put('reports', 'latest');
    Storage::disk('local')->assertExists(["reports/{$first}", "reports/{$second}"]);
    expect(DB::table('versioned_storage_versions')->where('version', $first)->value('pending_delete'))->toBe(1);

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    Storage::disk('local')->assertMissing("reports/{$first}");
    Storage::disk('local')->assertExists("reports/{$second}");
    expect(VersionedStorage::get('reports'))->toBe('latest');
})->with([false, true]);

it('does not delete old snapshots on nested commit or rollback or termination', function (bool $commit) {
    $first = VersionedStorage::put('reports', 'original');
    DB::beginTransaction();
    DB::beginTransaction();
    $second = VersionedStorage::put('reports', 'replacement', cleanup: true);
    DB::commit();
    app()->terminate();
    Storage::disk('local')->assertExists(["reports/{$first}", "reports/{$second}"]);

    if ($commit) {
        DB::commit();
    } else {
        DB::rollBack();
    }

    app()->terminate();
    Storage::disk('local')->assertExists("reports/{$first}");
    expect(VersionedStorage::get('reports'))->toBe($commit ? 'replacement' : 'original');
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    if ($commit) {
        Storage::disk('local')->assertMissing("reports/{$first}");
    } else {
        Storage::disk('local')->assertExists("reports/{$first}");
        Storage::disk('local')->assertMissing("reports/{$second}");
    }
})->with([false, true]);

it('refuses command cleanup inside an active metadata transaction', function () {
    $first = VersionedStorage::put('reports', 'original');
    DB::beginTransaction();
    VersionedStorage::put('reports', 'replacement', cleanup: true);

    try {
        $this->artisan('laravel-versioned-storage:cleanup')
            ->expectsOutputToContain('Version cleanup cannot run inside an active metadata transaction.')
            ->assertFailed();
        Storage::disk('local')->assertExists("reports/{$first}");
    } finally {
        DB::rollBack();
    }

    expect(VersionedStorage::get('reports'))->toBe('original');
});

it('loads bounded chunks and removes successful metadata with one delete query per chunk', function () {
    $retired = [];
    foreach (range(1, 5) as $index) {
        $retired[] = VersionedStorage::put('reports', "version {$index}");
    }
    $current = VersionedStorage::put('reports', 'current', cleanup: true);
    $sizes = [];
    Concurrency::shouldReceive('run')->andReturnUsing(function (array $tasks) use (&$sizes): array {
        $sizes[] = count($tasks);

        return array_map(fn (Closure $task): array => $task(), $tasks);
    });
    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    try {
        $this->artisan('laravel-versioned-storage:cleanup', ['--chunk' => 2])->assertSuccessful();
        $queries = $connection->getQueryLog();
    } finally {
        $connection->disableQueryLog();
    }

    expect($sizes)->toBe([2, 2, 1]);
    $deletes = array_filter($queries, fn (array $query): bool => str_starts_with($query['query'], 'delete '));
    $writes = array_filter($queries, fn (array $query): bool => preg_match('/^(insert|update|delete) /', $query['query']) === 1);
    expect($deletes)->toHaveCount(3)
        ->and($writes)->toHaveCount(3)
        ->and(VersionedStorage::versions('reports'))->toBe([$current]);
    foreach ($retired as $version) {
        Storage::disk('local')->assertMissing("reports/{$version}");
    }
    Storage::disk('local')->assertExists("reports/{$current}");
});

it('rejects invalid chunk sizes before invoking concurrent tasks', function (string $size) {
    Concurrency::shouldReceive('run')->never();
    $this->artisan('laravel-versioned-storage:cleanup', ['--chunk' => $size])
        ->expectsOutputToContain('The chunk size must be a positive integer.')
        ->assertFailed();
})->with(['0', '-1', 'invalid', '1.5']);

it('runs real process workers concurrently and reconciles their results in the parent command', function () {
    $database = tempnam(sys_get_temp_dir(), 'versioned-cleanup-');
    $disk = Storage::fake('concurrent');
    config()->set('database.connections.cleanup', [
        'driver' => 'sqlite',
        'database' => $database,
        'foreign_key_constraints' => true,
    ]);
    config()->set('laravel-versioned-storage.connection', 'cleanup');
    $first = null;
    $second = null;

    try {
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $path) {
            (require $path)->up();
        }

        $first = VersionedStorage::put('one', 'original', disk: 'concurrent');
        $second = VersionedStorage::put('two', 'original', disk: 'concurrent');
        $current = VersionedStorage::put('one', 'replacement', cleanup: true, disk: 'concurrent');
        VersionedStorage::put('two', 'replacement', cleanup: true, disk: 'concurrent');
        $process = new Process([
            PHP_BINARY, __DIR__.'/../Support/concurrent-cleanup/artisan',
            'laravel-versioned-storage:cleanup', '--chunk=2',
        ], null, [
            'VERSIONED_STORAGE_TEST_DB' => $database,
            'VERSIONED_STORAGE_TEST_ROOT' => $disk->path(''),
        ]);
        $process->setTimeout(30);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
        $disk->assertMissing(["one/{$first}", "two/{$second}"]);
        $disk->assertExists("one/{$current}");
        expect(glob($disk->path('worker-*')))->toHaveCount(2)
            ->and(DB::connection('cleanup')->table('versioned_storage_versions')->count())->toBe(2);
    } finally {
        config()->set('laravel-versioned-storage.connection', null);
        DB::purge('cleanup');
        unlink($database);
    }
});

it('retains failed deletions and continues past them to later chunks', function () {
    $retired = [];
    foreach (range(1, 5) as $index) {
        $retired[] = VersionedStorage::put('reports', "version {$index}");
    }
    $current = VersionedStorage::put('reports', 'current', cleanup: true);
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with("reports/{$retired[0]}")
        ->andThrow(new RuntimeException('deletion refused'));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, $disk->getConfig()));

    $this->artisan('laravel-versioned-storage:cleanup', ['--chunk' => 2])
        ->expectsOutputToContain('deletion refused')->assertFailed();
    expect(DB::table('versioned_storage_versions')->orderBy('id')->pluck('version')->all())->toBe([$retired[0], $current]);
    $disk->assertExists("reports/{$retired[0]}");
    foreach (array_slice($retired, 1) as $version) {
        $disk->assertMissing("reports/{$version}");
    }

    Storage::set('local', $disk);
    $this->artisan('laravel-versioned-storage:cleanup', ['--chunk' => 2])->assertSuccessful();
    $disk->assertMissing("reports/{$retired[0]}");
});

it('retains metadata after a worker process or bulk database failure and reconciles already absent files on retry', function (bool $databaseFailure) {
    $first = VersionedStorage::put('reports', 'original');
    VersionedStorage::put('reports', 'replacement', cleanup: true);
    $connection = DB::connection();

    if ($databaseFailure) {
        $connection->statement("CREATE TRIGGER reject_cleanup BEFORE DELETE ON versioned_storage_versions BEGIN SELECT RAISE(FAIL, 'database unavailable'); END");
    } else {
        Concurrency::shouldReceive('run')->once()->andReturnUsing(function (array $tasks): never {
            $tasks[0]();

            throw new RuntimeException('worker process interrupted');
        });
    }

    try {
        $this->artisan('laravel-versioned-storage:cleanup')->assertFailed();
        Storage::disk('local')->assertMissing("reports/{$first}");
        expect(DB::table('versioned_storage_versions')->where('version', $first)->value('pending_delete'))->toBe(1);
    } finally {
        if ($databaseFailure) {
            $connection->statement('DROP TRIGGER reject_cleanup');
        } else {
            Concurrency::swap(new ConcurrencyManager(app()));
        }
    }

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeFalse();
})->with([false, true]);

it('rejects missing or untrusted worker results without deleting any metadata', function (array $results) {
    $first = VersionedStorage::put('reports', 'original');
    VersionedStorage::put('reports', 'replacement', cleanup: true);
    Concurrency::shouldReceive('run')->once()->andReturn($results);

    $this->artisan('laravel-versioned-storage:cleanup')->assertFailed();
    expect(DB::table('versioned_storage_versions')->where('version', $first)->value('pending_delete'))->toBe(1);
    Storage::disk('local')->assertExists("reports/{$first}");
})->with([
    'missing results' => [[]],
    'unrelated version' => [[['version' => 'unknown', 'error' => null]]],
    'invalid structure' => [[true]],
]);

it('never selects a current revision even if its pending flag is corrupt', function () {
    $version = VersionedStorage::put('reports', 'current');
    DB::table('versioned_storage_versions')->update(['pending_delete' => true]);
    Concurrency::shouldReceive('run')->never();

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    Storage::disk('local')->assertExists("reports/{$version}");
    expect(DB::table('versioned_storage_versions')->count())->toBe(1);
});

it('reports worker disk configuration mismatches instead of deleting from a different root', function () {
    $first = VersionedStorage::put('reports', 'original');
    VersionedStorage::put('reports', 'replacement', cleanup: true);
    $disk = Storage::disk('local');
    $other = Storage::fake('other');
    $other->put("reports/{$first}", 'unrelated');
    $task = SnapshotDeletion::task('local', "reports/{$first}", $first, false,
        hash('sha256', json_encode($disk->getConfig(), JSON_THROW_ON_ERROR)));
    Storage::set('local', $other);

    $result = $task();
    expect($result['error'])->toBe('Disk [local] has different configuration in the cleanup worker.');
    $disk->assertExists("reports/{$first}");
    $other->assertExists("reports/{$first}");
});

it('preserves legacy nested resources while still cleaning other snapshots', function () {
    $parent = VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        $files->put('one', 'original');
    });
    $childPath = "reports/{$parent}/independent";
    $resource = DB::table('versioned_storage_resources')->insertGetId([
        'key' => hash('sha256', "local\0{$childPath}\0file"),
        'disk' => 'local',
        'path' => $childPath,
        'is_directory' => false,
    ]);
    $child = (string) Str::uuid();
    DB::table('versioned_storage_versions')->insert([
        'resource_id' => $resource,
        'version' => $child,
        'created_at' => now(),
    ]);
    Storage::disk('local')->put("{$childPath}/{$child}", 'independent');
    VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        $files->put('one', 'replacement');
    }, cleanup: true);
    $other = VersionedStorage::put('other', 'original');
    VersionedStorage::put('other', 'replacement', cleanup: true);

    $this->artisan('laravel-versioned-storage:cleanup', ['--chunk' => 1])
        ->expectsOutputToContain('contains registered resource')
        ->assertFailed();
    Storage::disk('local')->assertExists(["reports/{$parent}/one", "{$childPath}/{$child}"]);
    Storage::disk('local')->assertMissing("other/{$other}");
    expect(DB::table('versioned_storage_versions')->where('version', $parent)->value('pending_delete'))->toBe(1);
});

it('keeps processing later chunks when every deletion in the first chunk fails', function () {
    $retired = [];
    foreach (range(1, 4) as $index) {
        $retired[] = VersionedStorage::put('reports', "version {$index}");
    }
    VersionedStorage::put('reports', 'current', cleanup: true);
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    foreach (array_slice($retired, 0, 2) as $version) {
        $adapter->shouldReceive('delete')->once()->with("reports/{$version}")
            ->andThrow(new RuntimeException('deletion refused'));
    }
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, $disk->getConfig()));

    $this->artisan('laravel-versioned-storage:cleanup', ['--chunk' => 2])->assertFailed();
    expect(DB::table('versioned_storage_versions')->where('pending_delete', true)->orderBy('id')->pluck('version')->all())
        ->toBe(array_slice($retired, 0, 2));
    foreach (array_slice($retired, 2) as $version) {
        $disk->assertMissing("reports/{$version}");
    }
});

it('leaves newly created retired versions to the next command run', function () {
    $first = VersionedStorage::put('reports', 'original');
    $second = VersionedStorage::put('reports', 'replacement', cleanup: true);
    $current = null;
    Concurrency::shouldReceive('run')->once()->andReturnUsing(function (array $tasks) use (&$current): array {
        $current = VersionedStorage::put('reports', 'new publication', cleanup: true);

        return array_map(fn (Closure $task): array => $task(), $tasks);
    });

    $this->artisan('laravel-versioned-storage:cleanup', ['--chunk' => 1])->assertSuccessful();
    Storage::disk('local')->assertMissing("reports/{$first}");
    Storage::disk('local')->assertExists(["reports/{$second}", "reports/{$current}"]);
    expect(DB::table('versioned_storage_versions')->where('version', $second)->value('pending_delete'))->toBe(1);
});

it('retains metadata when a concurrent deletion silently leaves its snapshot behind', function (bool $directory) {
    $write = static fn (string $contents): string => $directory
        ? VersionedStorage::directory('reports', function (FilesystemAdapter $files) use ($contents): void {
            $files->put('one', $contents);
        }, cleanup: true)
        : VersionedStorage::put('reports', $contents, cleanup: true);
    $first = $write('original');
    $write('replacement');
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive($directory ? 'deleteDirectory' : 'delete')->once()
        ->with("reports/{$first}")->andReturnNull();
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, $disk->getConfig()));

    $this->artisan('laravel-versioned-storage:cleanup')->assertFailed();
    $disk->assertExists("reports/{$first}");
    expect(DB::table('versioned_storage_versions')->where('version', $first)->value('pending_delete'))->toBe(1);

    Storage::set('local', $disk);
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    $disk->assertMissing("reports/{$first}");
})->with([false, true]);
