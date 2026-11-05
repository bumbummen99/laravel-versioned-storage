<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use SkyRaptor\LaravelVersionedStorage\UploadClock;
use SkyRaptor\LaravelVersionedStorage\UploadDeadline;

it('uses an injectable monotonic clock to reject an expired operation', function () {
    $clock = new class extends UploadClock
    {
        public int $nanoseconds = 0;

        public function monotonic(): int
        {
            return $this->nanoseconds;
        }
    };
    $deadline = new UploadDeadline(10, false, $clock);
    $clock->nanoseconds = 9_500_000_000;
    expect($deadline->remainingSeconds())->toBe(1);
    $clock->nanoseconds = 10_000_000_000;
    expect(fn () => $deadline->assertCanStart())->toThrow(RuntimeException::class, 'timed out before it could start');
});

it('also rejects expiration of the wall clock used by recovery', function () {
    $clock = new class extends UploadClock
    {
        public function monotonic(): int
        {
            return 0;
        }
    };
    Carbon::setTestNow('2026-01-01 00:00:00');

    try {
        $deadline = new UploadDeadline(10, false, $clock);
        Carbon::setTestNow('2026-01-01 00:00:10');
        expect(fn () => $deadline->assertCanStart())->toThrow(RuntimeException::class, 'timed out before it could start');
    } finally {
        Carbon::setTestNow();
    }
});

it('keeps recovery rounding and the fork alarm safety margin in one place', function (bool $fork, string $expected) {
    Carbon::setTestNow('2026-01-01 00:00:00.250000');

    try {
        $deadline = new UploadDeadline(10, $fork, new UploadClock);
        expect($deadline->recoveryAfter(20)->toDateTimeString())->toBe($expected)
            ->and($deadline->recoveryAfter(20)->toDateTimeString())->toBe($expected);
    } finally {
        Carbon::setTestNow();
    }
})->with([
    [false, '2026-01-01 00:00:31'],
    [true, '2026-01-01 00:00:32'],
]);
