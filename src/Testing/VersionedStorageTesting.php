<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Testing;

use LogicException;
use SkyRaptor\LaravelVersionedStorage\UploadExecutionPolicy;

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
