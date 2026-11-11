<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Testing;

use LogicException;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Contracts\UploadExecutionPolicy;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Policies\SynchronousUploadExecution;

/**
 * Switches the current test application's execution-policy binding and restores runtime policy.
 * Stateless; does not replace filesystem/metadata services or affect later application instances.
 */
final class VersionedStorageTesting
{
    public static function runUploadsSynchronously(): void
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('Synchronous upload execution is only available while running tests.');
        }

        app()->instance(UploadExecutionPolicy::class, app(SynchronousUploadExecution::class));
    }

    public static function restoreUploadExecution(): void
    {
        app()->forgetInstance(UploadExecutionPolicy::class);
    }
}
