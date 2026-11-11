<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('preserves distinct directory versions and selects the latest snapshot by default', function () {
    $path = 'reports/monthly';
    $directory = VersionedStorage::directory($path);

    $first = VersionedStorage::directory($path, function (FilesystemAdapter $files): void {
        $files->put('summary.txt', 'first report');
        $files->put('details/data.csv', 'first details');
        $files->put('first-only.txt', 'first only');
        expect($files->allFiles())->toBe(['details/data.csv', 'first-only.txt', 'summary.txt']);
    });
    expect($directory->exists('summary.txt'))->toBeTrue()
        ->and($directory->get('summary.txt'))->toBe('first report')
        ->and($directory->exists('details/data.csv'))->toBeTrue()
        ->and($directory->get('details/data.csv'))->toBe('first details');

    $stage = VersionedStorage::stageDirectory($path, function (FilesystemAdapter $files) use ($directory, $first): void {
        $files->put('summary.txt', 'second report');
        $files->put('details/data.csv', 'second details');
        expect($directory->get('summary.txt'))->toBe('first report')
            ->and($directory->versions())->toBe([$first]);
    });
    expect($directory->versions())->toBe([$first]);
    Storage::disk('local')->assertExists("{$path}/{$stage->version}/summary.txt");
    $this->travel(365)->days();
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('ready');
    DB::beginTransaction();
    $second = VersionedStorage::staged($stage->version)->publish();
    expect($second)->toBe($stage->version);
    DB::commit();
    expect($directory->exists('summary.txt'))->toBeTrue()
        ->and($directory->get('summary.txt'))->toBe('second report')
        ->and($directory->exists('details/data.csv'))->toBeTrue()
        ->and($directory->get('details/data.csv'))->toBe('second details');

    $third = VersionedStorage::directory($path, function (FilesystemAdapter $files): void {
        $files->put('summary.txt', 'third report');
        $files->put('details/data.csv', 'third details');
    });
    expect($directory->exists('summary.txt'))->toBeTrue()
        ->and($directory->get('summary.txt'))->toBe('third report')
        ->and($directory->exists('details/data.csv'))->toBeTrue()
        ->and($directory->get('details/data.csv'))->toBe('third details')
        ->and($directory->exists('summary.txt', $first))->toBeTrue()
        ->and($directory->get('summary.txt', $first))->toBe('first report')
        ->and($directory->exists('details/data.csv', $first))->toBeTrue()
        ->and($directory->get('details/data.csv', $first))->toBe('first details')
        ->and($directory->exists('summary.txt', $second))->toBeTrue()
        ->and($directory->get('summary.txt', $second))->toBe('second report')
        ->and($directory->exists('details/data.csv', $second))->toBeTrue()
        ->and($directory->get('details/data.csv', $second))->toBe('second details')
        ->and($directory->exists('summary.txt', $third))->toBeTrue()
        ->and($directory->get('summary.txt', $third))->toBe('third report')
        ->and($directory->exists('details/data.csv', $third))->toBeTrue()
        ->and($directory->get('details/data.csv', $third))->toBe('third details')
        ->and($directory->versions())->toBe([$first, $second, $third])
        ->and($directory->exists('first-only.txt'))->toBeFalse()
        ->and($directory->exists('first-only.txt', $first))->toBeTrue()
        ->and(Storage::disk('local')->get("{$path}/{$first}/summary.txt"))->toBe('first report')
        ->and(Storage::disk('local')->get("{$path}/{$second}/summary.txt"))->toBe('second report')
        ->and(Storage::disk('local')->get("{$path}/{$third}/summary.txt"))->toBe('third report');
});

it('retires previous directory versions without hiding files in the current snapshot', function () {
    $path = 'reports/monthly';
    $first = VersionedStorage::directory($path, function (FilesystemAdapter $files): void {
        $files->put('summary.txt', 'first report');
        $files->put('details/data.csv', 'first details');
    }, cleanup: true);
    $second = VersionedStorage::directory($path, function (FilesystemAdapter $files): void {
        $files->put('summary.txt', 'second report');
        $files->put('details/data.csv', 'second details');
    }, cleanup: true);
    $current = VersionedStorage::directory($path, function (FilesystemAdapter $files): void {
        $files->put('summary.txt', 'current report');
        $files->put('details/data.csv', 'current details');
    }, cleanup: true);
    $directory = VersionedStorage::directory($path);

    expect($directory->exists('summary.txt', $first))->toBeFalse()
        ->and($directory->exists('summary.txt', $second))->toBeFalse()
        ->and($directory->exists('summary.txt', $current))->toBeTrue()
        ->and($directory->exists('details/data.csv', $first))->toBeFalse()
        ->and($directory->exists('details/data.csv', $second))->toBeFalse()
        ->and($directory->exists('details/data.csv', $current))->toBeTrue()
        ->and($directory->get('summary.txt', $current))->toBe('current report')
        ->and($directory->get('details/data.csv', $current))->toBe('current details')
        ->and($directory->get('summary.txt'))->toBe('current report')
        ->and($directory->versions())->toBe([$current]);
    Storage::disk('local')->assertExists(["{$path}/{$first}/summary.txt", "{$path}/{$second}/summary.txt"]);

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    Storage::disk('local')->assertMissing(["{$path}/{$first}", "{$path}/{$second}"]);
    Storage::disk('local')->assertExists("{$path}/{$current}/summary.txt");
    expect($directory->get('summary.txt'))->toBe('current report');
});
