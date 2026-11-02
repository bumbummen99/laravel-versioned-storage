<?php

declare(strict_types=1);

use LaravelVersionedStorage\VersionedStorage\VersionedStorage;

it('resolves the singleton', function () {
    expect(app(VersionedStorage::class))->toBeInstanceOf(VersionedStorage::class);
});

it('returns the same instance from the container', function () {
    expect(app(VersionedStorage::class))->toBe(app(VersionedStorage::class));
});

it('merges the package config', function () {
    expect(config('laravel-versioned-storage.placeholder'))->toBe('default');
});

it('loads the package translations', function () {
    expect(trans('laravel-versioned-storage::messages.placeholder'))->toBe('VersionedStorage placeholder translation.');
});

it('loads the package views', function () {
    expect(view()->exists('laravel-versioned-storage::placeholder'))->toBeTrue();
});

it('registers the artisan command', function () {
    $this->artisan('laravel-versioned-storage:placeholder')
        ->expectsOutputToContain('VersionedStorage placeholder command executed.')
        ->assertSuccessful();
});
