<?php

namespace SkyRaptor\LaravelVersionedStorage;

use SkyRaptor\LaravelVersionedStorage\Commands\LaravelVersionedStorageCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelVersionedStorageServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-versioned-storage')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_versioned_storage_table')
            ->hasCommand(LaravelVersionedStorageCommand::class);
    }
}
