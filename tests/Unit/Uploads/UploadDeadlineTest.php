<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestUploadClock;
use SkyRaptor\LaravelVersionedStorage\UploadDeadline;

it('uses an injectable monotonic clock to reject an expired operation', function () {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01 00:00:00'));
    $deadline = new UploadDeadline(10, false, $clock);
    $clock->nanoseconds = 9_500_000_000;
    expect($deadline->remainingSeconds())->toBe(1);
    $clock->nanoseconds = 10_000_000_000;
    expect(fn () => $deadline->assertCanStart())->toThrow(RuntimeException::class, 'timed out before it could start');
});

it('also rejects expiration of the wall clock used by recovery', function () {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01 00:00:00'));
    $deadline = new UploadDeadline(10, false, $clock);
    $clock->time->addSeconds(10);
    expect(fn () => $deadline->assertCanStart())->toThrow(RuntimeException::class, 'timed out before it could start');
});

it('keeps recovery rounding and the fork alarm safety margin in one place', function (bool $fork, string $expected) {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01 00:00:00.250000'));
    $deadline = new UploadDeadline(10, $fork, $clock);
    expect($deadline->recoveryAfter(20)->toDateTimeString())->toBe($expected)
        ->and($deadline->recoveryAfter(20)->toDateTimeString())->toBe($expected);
})->with([
    [false, '2026-01-01 00:00:31'],
    [true, '2026-01-01 00:00:32'],
]);

it('does not extend a monotonic budget when the wall clock moves backwards', function () {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01 00:00:00'));
    $deadline = new UploadDeadline(10, false, $clock);
    $clock->time->subHour();
    $clock->nanoseconds = 10_000_000_000;

    expect(fn () => $deadline->assertCanStart())->toThrow(RuntimeException::class, 'timed out before it could start');
});
