<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Policies;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Contracts\UploadExecutionPolicy;

/**
 * Test execution policy: validate the configured timeout and execute operations in the parent.
 * Bound to the current test application, with configuration read on each admission.
 * Does not bypass journals, transaction guards, or recovery deadlines.
 * Changes filesystem-operation supervision only, not cleanup-worker concurrency.
 */
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
