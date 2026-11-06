<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Testing;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\Uploads\UploadExecutionPolicy;

final class SynchronousUploadExecution implements UploadExecutionPolicy
{
    public function __construct(private readonly Repository $config) {}

    public function execution(): array
    {
        $timeout = filter_var(
            $this->config->get('laravel-versioned-storage.upload_timeout'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($timeout === false) {
            throw new RuntimeException('The effective upload timeout must be a positive number of seconds.');
        }

        return ['timeout' => $timeout, 'fork' => false];
    }
}
