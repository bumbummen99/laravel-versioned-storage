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

            if (getenv('VERSIONED_STORAGE_TEST_WORKLOAD') === 'cpu') {
                $until = hrtime(true) + 10_000_000_000;
                while (hrtime(true) < $until) {
                    hash('sha256', 'CPU-bound upload');
                }
            } elseif (getenv('VERSIONED_STORAGE_TEST_WORKLOAD') === 'blocked' || getenv('VERSIONED_STORAGE_TEST_SLOW') === '1') {
                // On Unix, sleeping does not consume PHP's max_execution_time budget.
                sleep(8);
            }
            $files->put('finished', 'contents');
        });
        $stage->publish();

        if (PHP_SAPI === 'cli' && Artisan::call('laravel-versioned-storage:cleanup') !== 0) {
            throw new RuntimeException(Artisan::output());
        }

        return "parent-pid: {$parent}\npublished\n";
    }
}
