<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

beforeEach(function (): void {
    Storage::set('tenant', Storage::build([
        'driver' => 'scoped',
        'disk' => [
            'driver' => 'local',
            'root' => Storage::disk('local')->path(''),
        ],
        'prefix' => 'tenants/acme',
    ]));
});

it('writes versions inside the selected disks configured prefix', function (bool $directory) {
    $version = $directory
        ? VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'contents');
        }, disk: 'tenant')
        : VersionedStorage::put('reports', 'contents', disk: 'tenant');
    $relative = "reports/{$version}".($directory ? '/summary.txt' : '');

    Storage::disk('tenant')->assertExists($relative);
    expect(Storage::disk('tenant')->get($relative))->toBe('contents');
    Storage::disk('local')->assertExists("tenants/acme/{$relative}");
    Storage::disk('local')->assertMissing($relative);
})->with([false, true]);

it('keeps scoped paths and public URLs consistent with the physical snapshot', function () {
    $url = null;
    $path = null;
    $version = VersionedStorage::directory('reports', function (FilesystemAdapter $files) use (&$url, &$path): void {
        $files->put('summary.txt', 'contents');
        $url = $files->url('summary.txt');
        $path = $files->path('summary.txt');
        expect($files->allFiles())->toBe(['summary.txt']);
        expect(fn () => $files->put('../escaped.txt', 'invalid'))->toThrow(PathTraversalDetected::class);
    }, disk: 'tenant');
    $relative = "reports/{$version}/summary.txt";

    expect($path)->toBe(Storage::disk('tenant')->path($relative))
        ->and($url)->toBe(Storage::disk('tenant')->url($relative));
    Storage::disk('tenant')->assertExists($relative);
});

it('removes rolled back snapshots within the disk prefix', function () {
    $first = VersionedStorage::put('reports', 'original', disk: 'tenant');
    $connection = DB::connection();
    $connection->beginTransaction();

    try {
        $second = VersionedStorage::put('reports', 'staged', true, 'tenant');
        Storage::disk('tenant')->assertExists("reports/{$second}");
    } finally {
        $connection->rollBack();
    }

    expect(VersionedStorage::get('reports', disk: 'tenant'))->toBe('original')
        ->and(Storage::disk('local')->allFiles())->toBe(["tenants/acme/reports/{$first}"]);
});

it('preserves the combined prefix when scoping an already prefixed disk', function () {
    Storage::set('nested', Storage::build([
        'driver' => 'scoped',
        'disk' => [
            'driver' => 'local',
            'root' => Storage::disk('local')->path(''),
            'prefix' => 'tenants',
        ],
        'prefix' => 'acme',
    ]));
    $version = VersionedStorage::put('reports', 'contents', disk: 'nested');

    Storage::disk('local')->assertExists("tenants/acme/reports/{$version}");
    expect(Storage::disk('local')->allFiles())->toBe(["tenants/acme/reports/{$version}"])
        ->and(VersionedStorage::get('reports', disk: 'nested'))->toBe('contents');
});

it('reads versions through the selected disks configured prefix', function (bool $directory) {
    $version = $directory
        ? VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'contents');
        }, disk: 'tenant')
        : VersionedStorage::put('reports', 'contents', disk: 'tenant');
    $relative = "reports/{$version}".($directory ? '/summary.txt' : '');
    Storage::disk('tenant')->put($relative, 'authoritative scoped contents');
    Storage::disk('tenant')->assertExists($relative);

    expect($directory
        ? VersionedStorage::directory('reports', disk: 'tenant')->get('summary.txt', $version)
        : VersionedStorage::get('reports', $version, 'tenant'))->toBe('authoritative scoped contents');
})->with([false, true]);

it('removes retired physical files on a prefixed disk rather than only their metadata', function (bool $directory) {
    $first = $directory
        ? VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'original');
        }, disk: 'tenant')
        : VersionedStorage::put('reports', 'original', disk: 'tenant');

    if ($directory) {
        VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'replacement');
        }, cleanup: true, disk: 'tenant');
    } else {
        VersionedStorage::put('reports', 'replacement', cleanup: true, disk: 'tenant');
    }

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    $relative = "reports/{$first}".($directory ? '/summary.txt' : '');
    expect($directory
        ? VersionedStorage::directory('reports', disk: 'tenant')->versions()
        : VersionedStorage::versions('reports', 'tenant'))->toHaveCount(1);
    Storage::disk('tenant')->assertMissing($relative);
    Storage::disk('local')->assertMissing($relative);
    Storage::disk('local')->assertMissing("tenants/acme/{$relative}");
})->with([false, true]);
