<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Filesystem\Execution;

use Illuminate\Support\Carbon;

/**
 * Supplies wall-clock and monotonic time for operation deadlines.
 * Stateless and resolved when each deadline starts; tests may bind a deterministic clock.
 */
class UploadClock
{
    public function now(): Carbon
    {
        return now();
    }

    public function monotonic(): int
    {
        return hrtime(true);
    }
}
