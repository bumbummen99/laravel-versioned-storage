<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use Symfony\Component\Process\Process;

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
