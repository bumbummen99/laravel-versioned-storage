<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToWriteFile;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('leaves the previous directory current and removes staged files when a callback fails', function () {
    $first = VersionedStorage::directory('foo/bar', function (FilesystemAdapter $files): void {
        $files->put('one', 'original');
    });

    expect(fn () => VersionedStorage::directory('foo/bar', function (FilesystemAdapter $files): void {
        $files->put('one', 'incomplete');

        throw new RuntimeException('callback failed');
    }, true))->toThrow(RuntimeException::class, 'callback failed');

    expect(VersionedStorage::directory('foo/bar')->versions())->toBe([$first])
        ->and(VersionedStorage::directory('foo/bar')->get('one'))->toBe('original')
        ->and(Storage::disk('local')->allFiles('foo/bar'))->toBe(["foo/bar/{$first}/one"]);
    $stage = DB::table('versioned_storage_stages')->where('state', 'uploading')->first();
    expect($stage)->not->toBeNull();
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('uploading');
    $this->travelTo(Carbon::parse($stage->cleanup_after));
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeFalse();
});

it('does not publish failed filesystem writes even on a non-throwing disk', function () {
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('write')->once()->andThrow(UnableToWriteFile::atLocation('file', 'write failed'));
    Storage::set('failing', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));

    expect(fn () => VersionedStorage::put('file', 'contents', disk: 'failing'))
        ->toThrow(UnableToWriteFile::class)
        ->and(VersionedStorage::versions('file', 'failing'))->toBe([])
        ->and(DB::table('versioned_storage_resources')->count())->toBe(1)
        ->and(DB::table('versioned_storage_resources')->value('current_version'))->toBeNull();
});

it('removes a staged version if its database publication fails', function () {
    $first = VersionedStorage::put('file', 'original');
    $connection = DB::connection();
    $connection->statement("CREATE TRIGGER reject_storage_version BEFORE INSERT ON versioned_storage_versions BEGIN SELECT RAISE(FAIL, 'publication failed'); END");

    try {
        expect(fn () => VersionedStorage::put('file', 'unpublished', true))
            ->toThrow(QueryException::class, 'publication failed');
    } finally {
        $connection->statement('DROP TRIGGER reject_storage_version');
    }

    expect(VersionedStorage::get('file'))->toBe('original')
        ->and(VersionedStorage::versions('file'))->toBe([$first])
        ->and(Storage::disk('local')->allFiles('file'))->toBe(["file/{$first}"]);
    $stage = DB::table('versioned_storage_stages')->where('state', 'uploading')->first();
    expect($stage)->not->toBeNull();
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeTrue();
    $this->travelTo(Carbon::parse($stage->cleanup_after));
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeFalse();
});
