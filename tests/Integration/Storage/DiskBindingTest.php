<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('keeps a rolled back stage bound to the original disk after its name is rebound', function () {
    $original = Storage::disk('local');
    $replacement = Storage::fake('replacement');
    $stage = VersionedStorage::stage('rebound', 'staged');
    DB::beginTransaction();
    $version = $stage->publish();
    $replacement->put("rebound/{$version}", 'unrelated');
    Storage::set('local', $replacement);
    DB::rollBack();

    expect($original->exists("rebound/{$version}"))->toBeTrue()
        ->and($replacement->get("rebound/{$version}"))->toBe('unrelated');

    $stage->discard();

    expect($original->exists("rebound/{$version}"))->toBeFalse()
        ->and($replacement->get("rebound/{$version}"))->toBe('unrelated');
});

it('does not automatically clean either disk when its name is rebound before commit', function () {
    $original = Storage::disk('local');
    $first = VersionedStorage::put('rebound-commit', 'original');
    $replacement = Storage::fake('replacement');
    $replacement->put("rebound-commit/{$first}", 'unrelated');
    $stage = VersionedStorage::stage('rebound-commit', 'replacement');
    DB::beginTransaction();
    Storage::set('local', $replacement);
    $second = $stage->publish(cleanup: true);
    DB::commit();

    expect($original->exists("rebound-commit/{$first}"))->toBeTrue()
        ->and($replacement->get("rebound-commit/{$first}"))->toBe('unrelated');
    Storage::set('local', $original);

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    expect($original->exists("rebound-commit/{$first}"))->toBeFalse()
        ->and($original->get("rebound-commit/{$second}"))->toBe('replacement')
        ->and($replacement->get("rebound-commit/{$first}"))->toBe('unrelated');
});
