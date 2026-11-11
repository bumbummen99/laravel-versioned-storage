<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Actions\ReadSnapshot;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotFilesystem;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

beforeEach(function (): void {
    Storage::fake('other');
    foreach (['local', 'other'] as $disk) {
        $resource = new ResourceIdentity('file', $disk, false);
        $id = DB::table('versioned_storage_resources')->insertGetId([
            'key' => $resource->key, 'disk' => $disk, 'path' => 'file', 'is_directory' => false, 'current_version' => $disk.'-version',
        ]);
        DB::table('versioned_storage_versions')->insert([
            'resource_id' => $id, 'version' => $disk.'-version', 'created_at' => now(),
        ]);
        Storage::disk($disk)->put('file/'.$disk.'-version', $disk.' contents');
    }
});

it('resolves package disk overrides without exposing versions from another disk', function () {
    config()->set('laravel-versioned-storage.disk', 'other');

    expect(VersionedStorage::get('file'))->toBe('other contents')
        ->and(VersionedStorage::versions('file'))->toBe(['other-version'])
        ->and(VersionedStorage::exists('file', 'local-version'))->toBeFalse()
        ->and(VersionedStorage::get('file', disk: 'local'))->toBe('local contents');
});

it('falls back to the Laravel default disk when no package override exists', function () {
    config()->set('laravel-versioned-storage.disk', null);
    config()->set('filesystems.default', 'other');

    expect(VersionedStorage::get('file'))->toBe('other contents')
        ->and(VersionedStorage::versions('file', 'local'))->toBe(['local-version']);
});

it('reports missing file resources without registering metadata or creating snapshots', function () {
    $resource = new ResourceIdentity('missing', 'local', false);
    $metadata = new ResourceMetadata(DB::connection(), $resource);
    $reads = new ReadSnapshot(DB::connection(), $resource, $metadata, new SnapshotFilesystem(Storage::disk('local'), $resource));

    expect($reads->exists(''))->toBeFalse()
        ->and($reads->versions())->toBe([])
        ->and(fn () => $reads->get(''))->toThrow(FileNotFoundException::class)
        ->and(DB::table('versioned_storage_resources')->where('path', 'missing')->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists('missing'))->toBeFalse();
});

it('reports missing directory files without registering a resource', function () {
    $resource = new ResourceIdentity('missing', 'local', true);
    $metadata = new ResourceMetadata(DB::connection(), $resource);
    $reads = new ReadSnapshot(DB::connection(), $resource, $metadata, new SnapshotFilesystem(Storage::disk('local'), $resource));

    expect($reads->exists('one'))->toBeFalse()
        ->and(fn () => $reads->get('one'))->toThrow(FileNotFoundException::class)
        ->and(DB::table('versioned_storage_resources')->where('path', 'missing')->exists())->toBeFalse();
});

it('rejects reloading a missing or discarded stage by UUID', function () {
    expect(fn () => VersionedStorage::staged('missing-stage'))
        ->toThrow(RuntimeException::class, 'does not exist or was discarded');
});
