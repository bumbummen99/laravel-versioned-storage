<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Uploads;

use Illuminate\Support\Carbon;

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
