<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestUploadClock;
use SkyRaptor\LaravelVersionedStorage\Uploads\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Uploads\UploadDeadline;

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

it('rounds every positive fractional alarm budget up to a whole second', function (int $elapsed, int $expected) {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01 00:00:00'));
    $deadline = new UploadDeadline(10, true, $clock);
    $clock->nanoseconds = $elapsed;

    expect($deadline->remainingSeconds())->toBe($expected);
})->with([
    'entire budget' => [0, 10],
    'less than half a second remains' => [9_750_000_000, 1],
    'just over one second remains' => [8_999_999_999, 2],
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
