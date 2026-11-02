<?php

declare(strict_types=1);

namespace LaravelVersionedStorage\VersionedStorage\Tests;

use LaravelVersionedStorage\VersionedStorage\VersionedStorageServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            VersionedStorageServiceProvider::class,
        ];
    }
}
