<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('writes and reads a file using a normalized leading-slash path', function () {
    $version = VersionedStorage::put('/foo/bar/baz', 'Hello, World!');

    expect(VersionedStorage::exists('/foo/bar/baz'))->toBeTrue()
        ->and(VersionedStorage::get('/foo/bar/baz'))->toBe('Hello, World!')
        ->and(VersionedStorage::get('foo/bar/baz', $version))->toBe('Hello, World!');
});

it('preserves the contents of each file version and selects the latest by default', function () {
    $path = 'reports/daily.csv';
    $versions = [];
    $contents = ['first report', 'second report', 'third report'];

    foreach ($contents as $content) {
        $versions[] = VersionedStorage::put($path, $content);
        expect(VersionedStorage::exists($path))->toBeTrue()
            ->and(VersionedStorage::get($path))->toBe($content);
    }

    foreach ($versions as $index => $version) {
        expect(VersionedStorage::exists($path, $version))->toBeTrue()
            ->and(VersionedStorage::get($path, $version))->toBe($contents[$index]);
    }
});

it('retires previous file versions on each replacement without hiding the current version', function () {
    $path = 'reports/daily.csv';
    $first = VersionedStorage::put($path, 'first report', cleanup: true);
    $second = VersionedStorage::put($path, 'second report', cleanup: true);
    $current = VersionedStorage::put($path, 'current report', cleanup: true);

    expect(VersionedStorage::exists($path, $first))->toBeFalse()
        ->and(VersionedStorage::exists($path, $second))->toBeFalse()
        ->and(VersionedStorage::exists($path, $current))->toBeTrue()
        ->and(VersionedStorage::get($path, $current))->toBe('current report')
        ->and(VersionedStorage::get($path))->toBe('current report')
        ->and(VersionedStorage::versions($path))->toBe([$current]);
});

it('leaves retired physical files for the cleanup command and preserves the current file', function () {
    $path = 'reports/daily.csv';
    $old = VersionedStorage::put($path, 'original');
    $current = VersionedStorage::put($path, 'replacement', cleanup: true);

    expect(VersionedStorage::versions($path))->toBe([$current])
        ->and(VersionedStorage::exists($path, $old))->toBeFalse();
    Storage::disk('local')->assertExists("{$path}/{$old}");

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    Storage::disk('local')->assertMissing("{$path}/{$old}");
    Storage::disk('local')->assertExists("{$path}/{$current}");
    expect(VersionedStorage::get($path))->toBe('replacement');
});

it('lists all available file versions from oldest to newest', function () {
    $path = 'reports/daily.csv';
    $versions = [
        VersionedStorage::put($path, 'first'),
        VersionedStorage::put($path, 'second'),
        VersionedStorage::put($path, 'third'),
    ];

    expect(VersionedStorage::versions($path))->toBe($versions);
});
