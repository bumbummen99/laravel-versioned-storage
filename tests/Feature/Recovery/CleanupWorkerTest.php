<?php

declare(strict_types=1);

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use SkyRaptor\LaravelVersionedStorage\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use Symfony\Component\Process\Process;

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
