<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToDeleteFile;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('reports stage recovery failures and leaves the failed stage available for retry', function () {
    $stage = VersionedStorage::stageDirectory('stage-recovery', function (FilesystemAdapter $files): void {
        $files->put('one', 'contents');
    });
    DB::table('versioned_storage_stages')->where('version', $stage->version)->update([
        'state' => 'discarding',
        'cleanup_after' => now()->subSecond(),
    ]);
    $disk = Storage::disk('local');
    DB::statement("CREATE TRIGGER reject_stage_recovery BEFORE INSERT ON versioned_storage_versions WHEN NEW.pending_delete = 1 BEGIN SELECT RAISE(FAIL, 'stage recovery failed'); END");
    $exceptionHandler = Mockery::mock(ExceptionHandler::class);
    $exceptionHandler->shouldReceive('report')->once()->with(Mockery::on(
        fn (Throwable $exception): bool => str_contains($exception->getMessage(), 'stage recovery failed'),
    ));
    app()->instance(ExceptionHandler::class, $exceptionHandler);

    try {
        $this->artisan('laravel-versioned-storage:cleanup')
            ->expectsOutputToContain('stage recovery failed')->assertFailed();
        expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeTrue();
        $disk->assertExists("stage-recovery/{$stage->version}/one");
    } finally {
        DB::statement('DROP TRIGGER reject_stage_recovery');
    }

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeFalse();
    $disk->assertMissing("stage-recovery/{$stage->version}");
});

it('reports each cleanup failure, deletes later snapshots, and rethrows the first failure', function () {
    $first = VersionedStorage::put('multiple-failures', 'first');
    $second = VersionedStorage::put('multiple-failures', 'second');
    $third = VersionedStorage::put('multiple-failures', 'third');
    $current = VersionedStorage::put('multiple-failures', 'current', cleanup: true);
    $firstFailure = new RuntimeException('first deletion refused');
    $secondFailure = new RuntimeException('second deletion refused');
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with("multiple-failures/{$first}")->andThrow($firstFailure);
    $adapter->shouldReceive('delete')->once()->with("multiple-failures/{$second}")->andThrow($secondFailure);
    $reported = [];
    $exceptionHandler = Mockery::mock(app(ExceptionHandler::class))->makePartial();
    $exceptionHandler->shouldReceive('report')->twice()->andReturnUsing(function (Throwable $exception) use (&$reported): void {
        $reported[] = $exception;
    });

    app()->instance(ExceptionHandler::class, $exceptionHandler);
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => true]));

    try {
        expect(fn () => VersionedStorage::cleanup('multiple-failures'))->toThrow($firstFailure);
        expect($reported)->toBe([$firstFailure, $secondFailure])
            ->and(DB::table('versioned_storage_versions')->orderBy('id')->pluck('version')->all())
            ->toBe([$first, $second, $current]);
        $disk->assertMissing("multiple-failures/{$third}");
        $disk->assertExists(["multiple-failures/{$first}", "multiple-failures/{$second}", "multiple-failures/{$current}"]);
    } finally {
        Storage::set('local', $disk);
    }
});

it('reports the upload failure when removing its incomplete snapshot also fails', function () {
    $uploadFailure = new RuntimeException('callback failed');
    $deletionFailure = new RuntimeException('snapshot deletion failed');
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('deleteDirectory')->once()->andThrow($deletionFailure);
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => true]));
    $exceptionHandler = Mockery::mock(ExceptionHandler::class);
    $exceptionHandler->shouldReceive('report')->once()->with($uploadFailure);
    app()->instance(ExceptionHandler::class, $exceptionHandler);

    try {
        expect(fn () => VersionedStorage::directory('failed-snapshot', fn () => throw $uploadFailure))
            ->toThrow($deletionFailure);
        expect(VersionedStorage::directory('failed-snapshot')->versions())->toBe([]);
    } finally {
        Storage::set('local', $disk);
    }
});

it('retains failed cleanup records and can retry cleanup without publishing again', function () {
    $first = VersionedStorage::put('retry', 'original');
    $second = VersionedStorage::put('retry', 'middle');
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with("retry/{$first}")
        ->andThrow(UnableToDeleteFile::atLocation("retry/{$first}"));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));

    VersionedStorage::put('retry', 'replacement', true);
    expect(fn () => VersionedStorage::cleanup('retry'))->toThrow(RuntimeException::class);
    expect(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeTrue();
    Storage::set('local', $disk);
    VersionedStorage::cleanup('retry');

    Storage::disk('local')->assertMissing(["retry/{$first}", "retry/{$second}"]);
    expect(VersionedStorage::get('retry'))->toBe('replacement')
        ->and(VersionedStorage::versions('retry'))->toHaveCount(1)
        ->and(DB::table('versioned_storage_versions')->count())->toBe(1);
});

it('preserves the committed version when command cleanup fails', function () {
    $first = VersionedStorage::put('deferred-failure', 'original');
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with("deferred-failure/{$first}")
        ->andThrow(UnableToDeleteFile::atLocation("deferred-failure/{$first}"));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));

    $stage = VersionedStorage::stage('deferred-failure', 'replacement');
    DB::beginTransaction();
    $second = $stage->publish(cleanup: true);
    Storage::disk('local')->assertExists("deferred-failure/{$first}");
    DB::commit();
    Storage::disk('local')->assertExists("deferred-failure/{$first}");
    $this->artisan('laravel-versioned-storage:cleanup')->assertFailed();
    expect(VersionedStorage::get('deferred-failure'))->toBe('replacement')
        ->and(VersionedStorage::versions('deferred-failure'))->toBe([$second])
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeTrue();

    Storage::set('local', $disk);
    VersionedStorage::cleanup('deferred-failure');
    Storage::disk('local')->assertMissing("deferred-failure/{$first}");
});

it('recovers failed discards of rolled back stages into durable cleanup work', function (bool $directory, bool $newResource, string $outer) {
    $versions = static fn (): array => $directory
        ? VersionedStorage::directory('rollback-retry')->versions()
        : VersionedStorage::versions('rollback-retry');
    $first = $newResource ? null : ($directory
        ? VersionedStorage::directory('rollback-retry', function (FilesystemAdapter $files): void {
            $files->put('one', 'original');
        })
        : VersionedStorage::put('rollback-retry', 'original'));
    $disk = Storage::disk('local');
    $refuseDeletion = false;
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $method = $directory ? 'deleteDirectory' : 'delete';
    $adapter->shouldReceive($method)->andReturnUsing(function (string $path) use (&$refuseDeletion, $disk, $method): void {
        if ($refuseDeletion) {
            throw new RuntimeException('snapshot deletion refused');
        }

        $disk->getAdapter()->$method($path);
    });
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));
    $stage = $directory
        ? VersionedStorage::stageDirectory('rollback-retry', function (FilesystemAdapter $files): void {
            $files->put('one', 'replacement');
        })
        : VersionedStorage::stage('rollback-retry', 'replacement');

    if ($outer !== 'none') {
        DB::beginTransaction();
    }

    DB::beginTransaction();
    $stage->publish(cleanup: true);
    DB::rollBack();

    if ($outer === 'commit') {
        DB::commit();
    } elseif ($outer === 'rollback') {
        DB::rollBack();
    }

    $expectedVersions = $first === null ? [] : [$first];
    expect(DB::transactionLevel())->toBe(0)
        ->and($versions())->toBe($expectedVersions)
        ->and(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('ready');
    $disk->assertExists($directory ? "rollback-retry/{$stage->version}/one" : "rollback-retry/{$stage->version}");
    $refuseDeletion = true;
    expect(fn () => $stage->discard())->toThrow(RuntimeException::class, 'snapshot deletion refused');
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('discarding');
    $this->artisan('laravel-versioned-storage:cleanup')
        ->expectsOutputToContain('Unable to remove version')
        ->assertFailed();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeFalse()
        ->and(DB::table('versioned_storage_versions')->where('version', $stage->version)->value('pending_delete'))->toBe(1);
    $disk->assertExists($directory ? "rollback-retry/{$stage->version}/one" : "rollback-retry/{$stage->version}");
    $refuseDeletion = false;
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeFalse()
        ->and(DB::table('versioned_storage_versions')->where('version', $stage->version)->exists())->toBeFalse()
        ->and($versions())->toBe($expectedVersions);
    $disk->assertMissing($directory ? "rollback-retry/{$stage->version}/one" : "rollback-retry/{$stage->version}");

    if ($first !== null) {
        expect($directory
            ? VersionedStorage::directory('rollback-retry')->get('one')
            : VersionedStorage::get('rollback-retry'))->toBe('original');
        $disk->assertExists("rollback-retry/{$first}");
    }
})->with([false, true])->with([false, true])->with(['none', 'commit', 'rollback']);

it('retries durable cleanup work left by an interrupted process', function () {
    $first = VersionedStorage::put('interrupted', 'original');
    $second = VersionedStorage::put('interrupted', 'replacement');
    DB::table('versioned_storage_versions')->where('version', $first)->update(['pending_delete' => true]);
    $directory = VersionedStorage::directory('directory', function (FilesystemAdapter $files): void {
        $files->put('one', 'original');
    });
    VersionedStorage::directory('directory', function (FilesystemAdapter $files): void {
        $files->put('two', 'replacement');
    });
    DB::table('versioned_storage_versions')->where('version', $directory)->update(['pending_delete' => true]);

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    Storage::disk('local')->assertMissing(["interrupted/{$first}", "directory/{$directory}/one"]);
    expect(VersionedStorage::get('interrupted'))->toBe('replacement')
        ->and(VersionedStorage::versions('interrupted'))->toBe([$second])
        ->and(VersionedStorage::directory('directory')->get('two'))->toBe('replacement');
});

it('continues cleanup of other resources and reports command failures for retry', function () {
    $first = VersionedStorage::put('failed', 'original');
    VersionedStorage::put('failed', 'replacement');
    $other = VersionedStorage::put('other', 'original');
    VersionedStorage::put('other', 'replacement');
    DB::table('versioned_storage_versions')->whereIn('version', [$first, $other])->update(['pending_delete' => true]);
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with("failed/{$first}")
        ->andThrow(UnableToDeleteFile::atLocation("failed/{$first}"));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));

    $this->artisan('laravel-versioned-storage:cleanup')
        ->expectsOutputToContain('Unable to remove version')
        ->assertFailed();
    Storage::disk('local')->assertMissing("other/{$other}");
    expect(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeTrue();
    Storage::set('local', $disk);
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
});
