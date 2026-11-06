<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;
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
        ->and(array_map('realpath', array_keys($config)))->toBe([realpath(TestPaths::root('config/laravel-versioned-storage.php'))])
        ->and($migrations)->toHaveCount(1)
        ->and(array_values($migrations))->toBe([database_path('migrations')]);
});

it('publishes only configuration and migrations under the combined tag', function () {
    $paths = VersionedStorageServiceProvider::pathsToPublish(
        VersionedStorageServiceProvider::class,
        'laravel-versioned-storage',
    );

    expect(array_values($paths))->toEqualCanonicalizing([
        config_path('laravel-versioned-storage.php'),
        database_path('migrations'),
    ]);
});

it('registers only the storage cleanup command', function () {
    $commands = array_filter(
        array_keys(Artisan::all()),
        fn (string $command): bool => str_starts_with($command, 'laravel-versioned-storage:'),
    );

    expect(array_values($commands))->toBe(['laravel-versioned-storage:cleanup']);
});
