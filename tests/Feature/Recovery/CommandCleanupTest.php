<?php

declare(strict_types=1);

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use SkyRaptor\LaravelVersionedStorage\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;
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
    $stage = VersionedStorage::stage('reports', 'replacement');
    DB::beginTransaction();
    DB::beginTransaction();
    $second = $stage->publish(cleanup: true);
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
        Storage::disk('local')->assertExists("reports/{$second}");
        $stage->discard();
        Storage::disk('local')->assertMissing("reports/{$second}");
    }
})->with([false, true]);

it('refuses command cleanup inside an active metadata transaction', function () {
    $first = VersionedStorage::put('reports', 'original');
    $stage = VersionedStorage::stage('reports', 'replacement');
    DB::beginTransaction();
    $stage->publish(cleanup: true);

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
    $database = tempnam(__DIR__, 'versioned-cleanup-');
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
        foreach (glob(TestPaths::root('database/migrations/*.php')) as $path) {
            (require $path)->up();
        }

        $first = VersionedStorage::put('one', 'original', disk: 'concurrent');
        $second = VersionedStorage::put('two', 'original', disk: 'concurrent');
        $current = VersionedStorage::put('one', 'replacement', cleanup: true, disk: 'concurrent');
        VersionedStorage::put('two', 'replacement', cleanup: true, disk: 'concurrent');
        $process = new Process([
            PHP_BINARY, TestPaths::root('tests/Support/concurrent-cleanup/artisan'),
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

it('recovers failed staging cleanup after a process is killed with an outer transaction open', function () {
    $database = tempnam(__DIR__, 'versioned-crash-');
    $disk = Storage::disk('local');
    config()->set('database.connections.recovery', [
        'driver' => 'sqlite',
        'database' => $database,
        'foreign_key_constraints' => true,
    ]);
    config()->set('laravel-versioned-storage.connection', 'recovery');
    $writer = new Process([
        PHP_BINARY, TestPaths::root('tests/Support/crash-recovery-worker.php'),
        $database, $disk->path(''), 'stage',
    ]);
    $writer->setTimeout(15);
    $connection = DB::connection('recovery');

    try {
        foreach (glob(TestPaths::root('database/migrations/*.php')) as $path) {
            (require $path)->up();
        }

        $first = VersionedStorage::directory('crash-retry', function (FilesystemAdapter $files): void {
            $files->put('one', 'original');
        });
        $unrelated = VersionedStorage::put('unrelated', 'leave intact');
        $writer->start();
        expect($writer->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'recovery-pending')))
            ->toBeTrue($writer->getOutput().$writer->getErrorOutput())
            ->and($writer->isRunning())->toBeTrue();

        $staged = array_values(array_diff($disk->directories('crash-retry'), ["crash-retry/{$first}"]));
        expect($staged)->toHaveCount(1)
            ->and($disk->get($staged[0].'/one'))->toBe('incomplete');
        // Kill without running Laravel's commit, rollback, or termination hooks.
        $writer->stop(0, 9);
        expect($writer->isRunning())->toBeFalse()
            ->and($writer->isSuccessful())->toBeFalse()
            ->and($connection->table('versioned_storage_versions')->where('pending_delete', true)->count())->toBe(0)
            ->and(VersionedStorage::directory('crash-retry')->versions())->toBe([$first]);
        $disk->assertExists($staged[0].'/one');

        $cleanup = new Process([
            PHP_BINARY, TestPaths::root('tests/Support/crash-recovery-worker.php'),
            $database, $disk->path(''), 'cleanup',
        ]);
        $cleanup->setTimeout(15);
        $cleanup->run();
        expect($cleanup->isSuccessful())->toBeTrue($cleanup->getOutput().$cleanup->getErrorOutput())
            ->and(VersionedStorage::directory('crash-retry')->get('one'))->toBe('original')
            ->and(VersionedStorage::get('unrelated'))->toBe('leave intact');
        $disk->assertExists(["crash-retry/{$first}/one", "unrelated/{$unrelated}"]);
        $disk->assertExists($staged[0].'/one');
        $stage = $connection->table('versioned_storage_stages')->where('version', basename($staged[0]))->first();
        expect($stage->state)->toBe('uploading');
        $this->travelTo(Carbon::parse($stage->cleanup_after));
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        $disk->assertMissing($staged[0]);
        expect($connection->table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeFalse();
    } finally {
        $writer->stop(0, 9);
        config()->set('laravel-versioned-storage.connection', null);
        DB::purge('recovery');
        unlink($database);
    }
});

it('reconciles file uploads after a writer crashes', function (string $boundary, bool $newResource) {
    $database = tempnam(__DIR__, 'versioned-upload-crash-');
    $disk = Storage::disk('local');
    config()->set('database.connections.recovery', [
        'driver' => 'sqlite',
        'database' => $database,
        'foreign_key_constraints' => true,
    ]);
    config()->set('laravel-versioned-storage.connection', 'recovery');
    $writer = new Process([
        PHP_BINARY, TestPaths::root('tests/Support/crash-recovery-worker.php'),
        $database, $disk->path(''), $boundary,
    ]);
    $writer->setTimeout(15);
    $connection = DB::connection('recovery');

    try {
        foreach (glob(TestPaths::root('database/migrations/*.php')) as $path) {
            (require $path)->up();
        }

        $first = $newResource ? null : VersionedStorage::put('crash-file', 'original');
        $unrelated = VersionedStorage::put('unrelated', 'leave intact');
        $disk->put('upload-source.txt', 'incomplete upload contents');
        $writer->start();
        expect($writer->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'writer-paused')))
            ->toBeTrue($writer->getOutput().$writer->getErrorOutput())
            ->and($writer->isRunning())->toBeTrue();
        $staged = array_values(array_diff($disk->allFiles('crash-file'), $first === null ? [] : ["crash-file/{$first}"]));
        $expectedContents = $boundary === 'upload' ? 'incomplete' : 'incomplete upload contents';
        expect($staged)->toHaveCount(1)
            ->and($disk->get($staged[0]))->toBe($expectedContents);
        $published = $boundary === 'committed-put' ? [basename($staged[0])] : ($first === null ? [] : [$first]);

        $writer->stop(0, 9);
        expect($writer->isRunning())->toBeFalse()
            ->and($writer->isSuccessful())->toBeFalse()
            ->and(VersionedStorage::versions('crash-file'))->toBe($published)
            ->and($connection->table('versioned_storage_versions')->where('pending_delete', true)->count())
            ->toBe($boundary === 'committed-put' && $first !== null ? 1 : 0);
        $disk->assertExists($staged[0]);

        $cleanup = new Process([
            PHP_BINARY, TestPaths::root('tests/Support/crash-recovery-worker.php'),
            $database, $disk->path(''), 'cleanup',
        ]);
        $cleanup->setTimeout(15);
        $cleanup->run();
        expect($cleanup->isSuccessful())->toBeTrue($cleanup->getOutput().$cleanup->getErrorOutput())
            ->and(VersionedStorage::versions('crash-file'))->toBe($published)
            ->and(VersionedStorage::get('unrelated'))->toBe('leave intact');
        $disk->assertExists("unrelated/{$unrelated}");
        $disk->assertExists($staged[0]);
        $version = basename($staged[0]);
        $stage = $connection->table('versioned_storage_stages')->where('version', $version)->first();
        expect($stage->state)->toBe(match ($boundary) {
            'committed-put' => 'published',
            'uncommitted-put' => 'ready',
            default => 'uploading',
        });
        $this->travelTo(Carbon::parse($stage->cleanup_after));
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

        if ($boundary === 'committed-put') {
            $disk->assertExists($staged[0]);
            expect(VersionedStorage::get('crash-file'))->toBe('incomplete upload contents');
        } elseif ($first !== null) {
            expect(VersionedStorage::get('crash-file'))->toBe('original');
            $disk->assertExists("crash-file/{$first}");
        }

        if ($boundary === 'uncommitted-put') {
            $disk->assertExists($staged[0]);
            $version = basename($staged[0]);
            expect(VersionedStorage::staged($version)->publish())->toBe($version)
                ->and(VersionedStorage::get('crash-file'))->toBe('incomplete upload contents');
        } elseif ($boundary !== 'committed-put') {
            $disk->assertMissing($staged[0]);
            expect($connection->table('versioned_storage_stages')->where('version', $version)->exists())->toBeFalse();
        }
    } finally {
        $writer->stop(0, 9);
        config()->set('laravel-versioned-storage.connection', null);
        DB::purge('recovery');
        unlink($database);
    }
})->with(['upload', 'before-publication', 'uncommitted-put', 'committed-put'])->with([false, true]);

it('does not reclaim a live upload while another process runs cleanup', function () {
    $database = tempnam(__DIR__, 'versioned-live-upload-');
    $disk = Storage::disk('local');
    config()->set('database.connections.recovery', [
        'driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true,
    ]);
    config()->set('laravel-versioned-storage.connection', 'recovery');
    $writer = new Process([
        PHP_BINARY, TestPaths::root('tests/Support/crash-recovery-worker.php'),
        $database, $disk->path(''), 'live-upload',
    ]);
    $writer->setTimeout(15);

    try {
        foreach (glob(TestPaths::root('database/migrations/*.php')) as $path) {
            (require $path)->up();
        }
        $disk->put('upload-source.txt', 'incomplete upload contents');
        $writer->start();
        expect($writer->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'writer-paused')))
            ->toBeTrue($writer->getOutput().$writer->getErrorOutput());
        $files = $disk->allFiles('crash-file');
        expect($files)->toHaveCount(1);
        $cleanup = new Process([
            PHP_BINARY, TestPaths::root('tests/Support/crash-recovery-worker.php'),
            $database, $disk->path(''), 'cleanup',
        ]);
        $cleanup->setTimeout(15);
        $cleanup->run();
        expect($cleanup->isSuccessful())->toBeTrue($cleanup->getOutput().$cleanup->getErrorOutput())
            ->and($writer->isRunning())->toBeTrue()
            ->and(DB::connection('recovery')->table('versioned_storage_stages')->where('version', basename($files[0]))->value('state'))->toBe('uploading')
            ->and($disk->get($files[0]))->toBe('incomplete');
        $disk->put('release-upload', 'release');
        expect($writer->wait())->toBe(0, $writer->getOutput().$writer->getErrorOutput())
            ->and(VersionedStorage::get('crash-file'))->toBe('incomplete upload contents');
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        $disk->assertExists($files[0]);
    } finally {
        $writer->stop(0, 9);
        config()->set('laravel-versioned-storage.connection', null);
        DB::purge('recovery');
        unlink($database);
    }
});

// An explicit disconnect models session loss; storage completion is simulated locally.
it('retains the recovery journal through late storage completion after explicit database disconnection', function () {
    $database = tempnam(__DIR__, 'versioned-session-loss-');
    $disk = Storage::disk('local');
    config()->set('database.connections.recovery', [
        'driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true,
    ]);
    config()->set('laravel-versioned-storage.connection', 'recovery');
    $writer = new Process([
        PHP_BINARY, TestPaths::root('tests/Support/crash-recovery-worker.php'),
        $database, $disk->path(''), 'session-loss',
    ]);
    $writer->setTimeout(15);
    $connection = DB::connection('recovery');

    try {
        foreach (glob(TestPaths::root('database/migrations/*.php')) as $path) {
            (require $path)->up();
        }
        $first = VersionedStorage::put('crash-file', 'original');
        $unrelated = VersionedStorage::put('unrelated', 'leave intact');
        $disk->put('upload-source.txt', 'incomplete upload contents');
        $writer->start();
        expect($writer->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'writer-paused')))
            ->toBeTrue($writer->getOutput().$writer->getErrorOutput())
            ->and($writer->isRunning())->toBeTrue();
        $staged = array_values(array_diff($disk->allFiles('crash-file'), ["crash-file/{$first}"]));
        expect($staged)->toHaveCount(1)
            ->and($disk->get($staged[0]))->toBe('incomplete');
        $version = basename($staged[0]);
        expect($connection->table('versioned_storage_stages')->where('version', $version)->value('state'))->toBe('uploading');

        $cleanup = new Process([
            PHP_BINARY, TestPaths::root('tests/Support/crash-recovery-worker.php'),
            $database, $disk->path(''), 'cleanup',
        ]);
        $cleanup->setTimeout(15);
        $cleanup->run();
        expect($cleanup->isSuccessful())->toBeTrue($cleanup->getOutput().$cleanup->getErrorOutput())
            ->and($writer->isRunning())->toBeTrue();
        expect($disk->get($staged[0]))->toBe('incomplete')
            ->and($connection->table('versioned_storage_stages')->where('version', $version)->value('state'))->toBe('uploading')
            ->and($connection->table('versioned_storage_versions')->where('version', $version)->exists())->toBeFalse();
        $deadline = Carbon::parse($connection->table('versioned_storage_stages')->where('version', $version)->value('cleanup_after'));
        expect($deadline->isFuture())->toBeTrue();

        $disk->put('release-upload', 'release');
        expect($writer->wait())->toBe(0, $writer->getOutput().$writer->getErrorOutput())
            ->and($writer->getOutput())->toContain(
                "storage-completed\n",
                "upload-failed: The upload lost its metadata connection before operation completion.\n",
            )
            ->and(VersionedStorage::versions('crash-file'))->toBe([$first])
            ->and($connection->table('versioned_storage_stages')->where('version', $version)->value('state'))->toBe('uploading')
            ->and($connection->table('versioned_storage_versions')->where('version', $version)->exists())->toBeFalse();

        // Failure handling may already delete the completed write, but must keep its journal.
        if ($disk->exists($staged[0])) {
            expect($disk->get($staged[0]))->toBe('incomplete upload contents');
        }
        expect(fn () => VersionedStorage::staged($version)->publish())->toThrow(RuntimeException::class, 'not ready');

        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        expect($connection->table('versioned_storage_stages')->where('version', $version)->value('state'))->toBe('uploading');
        $this->travelTo($deadline);
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        expect(VersionedStorage::get('crash-file'))->toBe('original')
            ->and(VersionedStorage::get('unrelated'))->toBe('leave intact')
            ->and($connection->table('versioned_storage_stages')->where('version', $version)->exists())->toBeFalse();
        $disk->assertExists(["crash-file/{$first}", "unrelated/{$unrelated}"]);
        $disk->assertMissing($staged[0]);
    } finally {
        $writer->stop(0, 9);
        config()->set('laravel-versioned-storage.connection', null);
        DB::purge('recovery');
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
