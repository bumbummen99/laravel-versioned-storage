<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadDeadline;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestUploadClock;

it('uses an injectable monotonic clock to reject an expired operation', function () {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01 00:00:00'));
    $deadline = new UploadDeadline(10, false, $clock);
    $clock->advanceMonotonicSeconds(9.5);
    expect($deadline->remainingSeconds())->toBe(1);
    $clock->advanceMonotonicSeconds(0.5);
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
    $clock->advanceMonotonicSeconds(10);

    expect(fn () => $deadline->assertCanStart())->toThrow(RuntimeException::class, 'timed out before it could start');
});

it('rounds every positive fractional alarm budget up to a whole second', function (float $elapsedSeconds, int $expectedSeconds) {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01 00:00:00'));
    $deadline = new UploadDeadline(10, true, $clock);
    $clock->advanceMonotonicSeconds($elapsedSeconds);

    expect($deadline->remainingSeconds())->toBe($expectedSeconds);
})->with([
    'entire budget' => [0, 10],
    'less than half a second remains' => [9.75, 1],
    'one nanosecond over one second remains' => [8.999999999, 2],
]);

it('checks expiration when requesting an alarm budget', function () {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01 00:00:00'));
    $deadline = new UploadDeadline(10, true, $clock);
    $clock->time->addSeconds(10);

    expect(fn () => $deadline->remainingSeconds())->toThrow(RuntimeException::class, 'timed out before it could start');
});

it('rejects a budget consumed between validation and calculating the alarm', function () {
    $clock = Mockery::mock(UploadClock::class);
    $clock->shouldReceive('now')->andReturn(Carbon::parse('2026-01-01 00:00:00'));
    $clock->shouldReceive('monotonic')->andReturn(0, 9_999_999_999, 10_000_000_000);
    $deadline = new UploadDeadline(10, true, $clock);

    try {
        expect(fn () => $deadline->remainingSeconds())->toThrow(RuntimeException::class, 'timed out before it could start');
    } finally {
        Mockery::close();
    }
});

it('lets either clock independently stop an operation at its boundary', function (int $wallSeconds, float $monotonicSeconds) {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01 00:00:00'));
    $deadline = new UploadDeadline(10, false, $clock);
    $clock->time->addSeconds($wallSeconds);
    $clock->advanceMonotonicSeconds($monotonicSeconds);

    expect(fn () => $deadline->assertCanStart())->toThrow(RuntimeException::class, 'timed out before it could start');
})->with([
    'wall clock reaches deadline alone' => [10, 0],
    'wall clock passes deadline alone' => [11, 0],
    'monotonic clock reaches deadline alone' => [0, 10],
    'monotonic clock passes deadline alone' => [0, 11],
]);

it('permits work while both independent clocks remain before expiry', function () {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01 00:00:00'));
    $deadline = new UploadDeadline(10, false, $clock);
    $clock->time->addMicroseconds(9_999_999);
    $clock->advanceMonotonicSeconds(9.999999999);

    $deadline->assertCanStart();
    expect($deadline->remainingSeconds())->toBe(1);
});

it('rounds an alarm budget correctly on either side of an exact second', function (float $elapsed, int $remaining) {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01 00:00:00'));
    $deadline = new UploadDeadline(10, true, $clock);
    $clock->advanceMonotonicSeconds($elapsed);

    expect($deadline->remainingSeconds())->toBe($remaining);
})->with([
    'just before a whole second' => [0.999999999, 10],
    'exactly a whole second' => [1, 9],
    'just after a whole second' => [1.000000001, 9],
    'exactly one second remains' => [9, 1],
    'one nanosecond remains' => [9.999999999, 1],
]);

it('keeps recovery deadlines fixed when callers advance clocks or mutate returned dates', function () {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01 00:00:00.250000'));
    $deadline = new UploadDeadline(10, true, $clock);
    $deadline->recoveryAfter(20)->addHour();
    $clock->time->addHour();
    $clock->advanceMonotonicSeconds(3600);

    expect($deadline->recoveryAfter(20)->toDateTimeString())->toBe('2026-01-01 00:00:32')
        ->and($deadline->recoveryAfter(0)->toDateTimeString())->toBe('2026-01-01 00:00:12');
});
