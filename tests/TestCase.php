<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            VersionedStorageServiceProvider::class,
        ];
    }
}
