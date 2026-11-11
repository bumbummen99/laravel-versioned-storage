<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('rejects publication on a prefixed disk even if the callback catches traversal', function () {
    Storage::set('tenant', Storage::build([
        'driver' => 'scoped',
        'disk' => ['driver' => 'local', 'root' => Storage::disk('local')->path('')],
        'prefix' => 'tenants/acme',
    ]));

    expect(fn () => VersionedStorage::directory('foo', function (FilesystemAdapter $files): void {
        expect(fn () => $files->put('../escaped', 'bad'))->toThrow(PathTraversalDetected::class);
    }, disk: 'tenant'))->toThrow(RuntimeException::class, 'snapshot failed')
        ->and(VersionedStorage::directory('foo', disk: 'tenant')->versions())->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('handles empty directory snapshots and empty file contents', function () {
    $version = VersionedStorage::directory('empty', function (FilesystemAdapter $files): void {});
    expect(Storage::disk('local')->directoryExists("empty/{$version}"))->toBeTrue()
        ->and(VersionedStorage::directory('empty')->versions())->toBe([$version]);

    VersionedStorage::put('empty-file', '');
    expect(VersionedStorage::get('empty-file'))->toBe('');
});
