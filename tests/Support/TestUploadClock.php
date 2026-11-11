<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Tests\Support;

use Illuminate\Support\Carbon;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;

final class TestUploadClock extends UploadClock
{
    public int $nanoseconds = 0;

    public function __construct(public Carbon $time) {}

    public function now(): Carbon
    {
        return $this->time->copy();
    }

    public function monotonic(): int
    {
        return $this->nanoseconds;
    }

    public function advanceMonotonicSeconds(float $seconds): void
    {
        $this->nanoseconds += (int) round($seconds * 1_000_000_000);
    }
}
