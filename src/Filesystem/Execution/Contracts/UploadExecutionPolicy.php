<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Contracts;

/**
 * Supplies the current operation timeout and supervision mode when an upload is admitted.
 * Implementations may track runtime context; callers must not cache policy across uploads.
 * Runtime and test policies share this seam; neither changes journals or cleanup concurrency.
 */
interface UploadExecutionPolicy
{
    /** @return array{timeout: int, fork: bool} */
    public function execution(): array;
}
