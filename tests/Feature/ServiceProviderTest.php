<?php

declare(strict_types=1);

use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageServiceProvider;

it('resolves the singleton', function () {
    expect(app(VersionedStorage::class))->toBeInstanceOf(VersionedStorage::class);
});

it('returns the same instance from the container', function () {
    expect(app(VersionedStorage::class))->toBe(app(VersionedStorage::class));
});

it('merges the package config', function () {
    expect(config('laravel-versioned-storage.disk'))->toBeNull()
        ->and(config('laravel-versioned-storage.connection'))->toBeNull();
});

it('registers publishable configuration and storage migrations', function () {
    $config = VersionedStorageServiceProvider::pathsToPublish(
        VersionedStorageServiceProvider::class,
        'laravel-versioned-storage-config',
    );
    $migrations = VersionedStorageServiceProvider::pathsToPublish(
        VersionedStorageServiceProvider::class,
        'laravel-versioned-storage-migrations',
    );

    expect(array_values($config))->toBe([config_path('laravel-versioned-storage.php')])
        ->and(array_map('realpath', array_keys($config)))->toBe([realpath(__DIR__.'/../../config/laravel-versioned-storage.php')])
        ->and($migrations)->toHaveCount(1)
        ->and(array_values($migrations))->toBe([database_path('migrations')]);
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
