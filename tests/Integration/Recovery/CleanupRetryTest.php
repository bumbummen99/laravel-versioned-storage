<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToDeleteFile;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

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
    $this->artisan('laravel-versioned-storage:cleanup')->expectsOutputToContain('Unable to remove version')->assertFailed();
    expect(VersionedStorage::get('deferred-failure'))->toBe('replacement')
        ->and(VersionedStorage::versions('deferred-failure'))->toBe([$second])
        ->and(VersionedStorage::exists('deferred-failure', $first))->toBeFalse()
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeTrue();

    Storage::set('local', $disk);
    VersionedStorage::cleanup('deferred-failure');
    Storage::disk('local')->assertMissing("deferred-failure/{$first}");
});

it('recovers failed discards of rolled back stages into durable cleanup work', function () {
    $first = VersionedStorage::directory('rollback-retry', static function (FilesystemAdapter $files): void {
        $files->put('one', 'original');
    });
    $disk = Storage::disk('local');
    $refuseDeletion = false;
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('deleteDirectory')->andReturnUsing(static function (string $path) use (&$refuseDeletion, $disk): void {
        if ($refuseDeletion) {
            throw new RuntimeException('snapshot deletion refused');
        }
        $disk->getAdapter()->deleteDirectory($path);
    });
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));
    $stage = VersionedStorage::stageDirectory('rollback-retry', static function (FilesystemAdapter $files): void {
        $files->put('one', 'replacement');
    });
    DB::beginTransaction();
    DB::beginTransaction();
    $stage->publish(cleanup: true);
    DB::rollBack();
    DB::rollBack();

    expect(DB::transactionLevel())->toBe(0)
        ->and(VersionedStorage::directory('rollback-retry')->versions())->toBe([$first])
        ->and(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('ready');
    $disk->assertExists("rollback-retry/{$stage->version}/one");
    $refuseDeletion = true;
    expect(fn () => $stage->discard())->toThrow(RuntimeException::class, 'snapshot deletion refused');
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('discarding');
    $this->artisan('laravel-versioned-storage:cleanup')->expectsOutputToContain('Unable to remove version')->assertFailed();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeFalse()
        ->and(DB::table('versioned_storage_versions')->where('version', $stage->version)->value('pending_delete'))->toBe(1);
    $disk->assertExists("rollback-retry/{$stage->version}/one");
    $refuseDeletion = false;
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeFalse()
        ->and(DB::table('versioned_storage_versions')->where('version', $stage->version)->exists())->toBeFalse()
        ->and(VersionedStorage::directory('rollback-retry')->versions())->toBe([$first])
        ->and(VersionedStorage::directory('rollback-retry')->get('one'))->toBe('original');
    $disk->assertMissing("rollback-retry/{$stage->version}");
    $disk->assertExists("rollback-retry/{$first}/one");
});

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
