<?php

declare(strict_types=1);

namespace Workbench\App;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

class UploadScenario
{
    public static function run(): string
    {
        $parent = getmypid();
        $path = PHP_SAPI === 'fpm-fcgi' ? 'fpm' : 'forked';
        $stage = VersionedStorage::stageDirectory($path, static function (FilesystemAdapter $files): void {
            $files->put('partial', (string) getmypid());

            $files->put('finished', 'contents');
        });
        $stage->publish();

        if (PHP_SAPI === 'cli' && Artisan::call('laravel-versioned-storage:cleanup') !== 0) {
            throw new RuntimeException(Artisan::output());
        }

        return "parent-pid: {$parent}\npublished\n";
    }
}
