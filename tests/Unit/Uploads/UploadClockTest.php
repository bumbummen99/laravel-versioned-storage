<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;

it('returns a fresh wall-clock value rather than sharing a mutable timestamp', function () {
    Carbon::withTestNow('2026-01-01 12:00:00', function (): void {
        $clock = new UploadClock;
        $clock->now()->addHour();

        expect($clock->now()->toDateTimeString())->toBe('2026-01-01 12:00:00');
    });
});

it('returns monotonic time in native nanoseconds', function () {
    $clock = new UploadClock;
    $before = hrtime(true);
    $observed = $clock->monotonic();
    $after = hrtime(true);

    expect($observed)->toBeInt()->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual($after);
});
