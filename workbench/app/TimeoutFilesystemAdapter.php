<?php

declare(strict_types=1);

namespace Workbench\App;

use League\Flysystem\Config;
use League\Flysystem\Local\LocalFilesystemAdapter;

class TimeoutFilesystemAdapter extends LocalFilesystemAdapter
{
    public function write(string $path, string $contents, Config $config): void
    {
        parent::write($path, basename($path) === 'partial' ? (string) getmypid() : $contents, $config);

        if (basename($path) !== 'partial') {
            return;
        }

        if (getenv('VERSIONED_STORAGE_TEST_WORKLOAD') === 'cpu') {
            $until = hrtime(true) + 10_000_000_000;
            while (hrtime(true) < $until) {
                hash('sha256', 'CPU-bound upload');
            }
        } elseif (getenv('VERSIONED_STORAGE_TEST_WORKLOAD') === 'blocked' || getenv('VERSIONED_STORAGE_TEST_SLOW') === '1') {
            sleep(8);
        }
    }
}
