<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Tests\Support;

use Illuminate\Support\Carbon;
use SkyRaptor\LaravelVersionedStorage\UploadClock;

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
}
