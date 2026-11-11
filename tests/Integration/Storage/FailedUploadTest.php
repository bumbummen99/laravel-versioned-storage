<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
