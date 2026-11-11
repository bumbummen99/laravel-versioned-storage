<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Facades\VersionedStorage as VersionedStorageFacade;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('preserves the contents of each file version and selects the latest by default', function () {
    $path = 'foo/bar/baz';
    $first = VersionedStorageFacade::put('/'.$path, 'first report');
    expect(VersionedStorage::exists('/'.$path))->toBeTrue()
        ->and(VersionedStorageFacade::get($path))->toBe('first report');

    $stage = VersionedStorage::stage($path, 'second report');
    expect(VersionedStorage::versions($path))->toBe([$first]);
    Storage::disk('local')->assertExists("{$path}/{$stage->version}");
    $this->travel(365)->days();
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('ready');
    DB::beginTransaction();
    $second = VersionedStorage::staged($stage->version)->publish();
    expect($second)->toBe($stage->version);
    DB::commit();
    expect(VersionedStorage::exists($path))->toBeTrue()
        ->and(VersionedStorage::get($path))->toBe('second report');

    $third = VersionedStorage::put($path, 'third report');
    expect(VersionedStorage::exists($path))->toBeTrue()
        ->and(VersionedStorage::get($path))->toBe('third report')
        ->and(VersionedStorage::exists($path, $first))->toBeTrue()
        ->and(VersionedStorage::get($path, $first))->toBe('first report')
        ->and(VersionedStorage::exists($path, $second))->toBeTrue()
        ->and(VersionedStorage::get($path, $second))->toBe('second report')
        ->and(VersionedStorage::exists($path, $third))->toBeTrue()
        ->and(VersionedStorage::get($path, $third))->toBe('third report')
        ->and(VersionedStorage::versions('/'.$path))->toBe([$first, $second, $third])
        ->and(Storage::disk('local')->get("{$path}/{$first}"))->toBe('first report')
        ->and(Storage::disk('local')->get("{$path}/{$second}"))->toBe('second report')
        ->and(Storage::disk('local')->get("{$path}/{$third}"))->toBe('third report');
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
    Storage::disk('local')->assertExists(["{$path}/{$first}", "{$path}/{$second}"]);

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    Storage::disk('local')->assertMissing(["{$path}/{$first}", "{$path}/{$second}"]);
    Storage::disk('local')->assertExists("{$path}/{$current}");
    expect(VersionedStorage::get($path))->toBe('current report');
});
