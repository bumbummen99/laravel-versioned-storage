<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('writes and reads multiple files within one directory snapshot', function () {
    $version = VersionedStorage::directory('reports/monthly', function (FilesystemAdapter $files): void {
        $files->put('summary.txt', 'monthly summary');
        $files->put('details/data.csv', 'monthly details');
    });
    $directory = VersionedStorage::directory('reports/monthly');

    expect($directory->exists('summary.txt'))->toBeTrue()
        ->and($directory->get('summary.txt'))->toBe('monthly summary')
        ->and($directory->exists('details/data.csv'))->toBeTrue()
        ->and($directory->get('details/data.csv', $version))->toBe('monthly details');
});

it('preserves distinct directory versions and selects the latest snapshot by default', function () {
    $path = 'reports/monthly';
    $versions = [];
    $contents = ['first report', 'second report', 'third report'];
    $directory = VersionedStorage::directory($path);

    foreach ($contents as $content) {
        $versions[] = VersionedStorage::directory($path, function (FilesystemAdapter $files) use ($content): void {
            $files->put('summary.txt', $content);
            $files->put('details/data.csv', "details: {$content}");
        });
        expect($directory->exists('summary.txt'))->toBeTrue()
            ->and($directory->get('summary.txt'))->toBe($content)
            ->and($directory->exists('details/data.csv'))->toBeTrue()
            ->and($directory->get('details/data.csv'))->toBe("details: {$content}");
    }

    foreach ($versions as $index => $version) {
        expect($directory->exists('summary.txt', $version))->toBeTrue()
            ->and($directory->get('summary.txt', $version))->toBe($contents[$index])
            ->and($directory->exists('details/data.csv', $version))->toBeTrue()
            ->and($directory->get('details/data.csv', $version))->toBe("details: {$contents[$index]}");
    }
});

it('retires previous directory versions without hiding files in the current snapshot', function () {
    $path = 'reports/monthly';
    $versions = [];

    foreach (['first report', 'second report', 'current report'] as $content) {
        $versions[] = VersionedStorage::directory($path, function (FilesystemAdapter $files) use ($content): void {
            $files->put('summary.txt', $content);
            $files->put('details/data.csv', "details: {$content}");
        }, cleanup: true);
    }
    [$first, $second, $current] = $versions;
    $directory = VersionedStorage::directory($path);

    foreach (['summary.txt', 'details/data.csv'] as $file) {
        expect($directory->exists($file, $first))->toBeFalse()
            ->and($directory->exists($file, $second))->toBeFalse()
            ->and($directory->exists($file, $current))->toBeTrue();
    }
    expect($directory->get('summary.txt', $current))->toBe('current report')
        ->and($directory->get('details/data.csv', $current))->toBe('details: current report')
        ->and($directory->get('summary.txt'))->toBe('current report')
        ->and($directory->versions())->toBe([$current]);
});

it('leaves retired physical directories for the cleanup command and preserves the current snapshot', function () {
    $old = VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        $files->put('one.txt', 'original');
    });
    $current = VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        $files->put('one.txt', 'replacement');
    }, cleanup: true);
    $directory = VersionedStorage::directory('reports');

    expect($directory->versions())->toBe([$current])
        ->and($directory->exists('one.txt', $old))->toBeFalse();
    Storage::disk('local')->assertExists("reports/{$old}/one.txt");

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    Storage::disk('local')->assertMissing("reports/{$old}");
    Storage::disk('local')->assertExists("reports/{$current}/one.txt");
    expect($directory->get('one.txt'))->toBe('replacement');
});

it('lists all available directory versions from oldest to newest', function () {
    $path = 'reports/monthly';
    $versions = [];

    foreach (['first', 'second', 'third'] as $content) {
        $versions[] = VersionedStorage::directory($path, function (FilesystemAdapter $files) use ($content): void {
            $files->put('summary.txt', $content);
        });
    }

    expect(VersionedStorage::directory($path)->versions())->toBe($versions);
});
