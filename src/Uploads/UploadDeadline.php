<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Uploads;

use Illuminate\Support\Carbon;
use RuntimeException;

final class UploadDeadline
{
    private const int NANOSECONDS_PER_SECOND = 1_000_000_000;

    private const int FORK_ALARM_ROUNDING_MARGIN_SECONDS = 1;

    private readonly Carbon $expiresAt;

    private readonly int $monotonicDeadline;

    public function __construct(
        public readonly int $timeout,
        private readonly bool $fork,
        private readonly UploadClock $clock,
    ) {
        $this->expiresAt = $clock->now()->copy()->addSeconds($timeout);
        $this->monotonicDeadline = $clock->monotonic() + $timeout * self::NANOSECONDS_PER_SECOND;
    }

    public function assertCanStart(): void
    {
        if ($this->clock->now()->greaterThanOrEqualTo($this->expiresAt)
            || $this->clock->monotonic() >= $this->monotonicDeadline) {
            throw new RuntimeException('The filesystem operation timed out before it could start.');
        }
    }

    public function remainingSeconds(): int
    {
        $this->assertCanStart();

        $remaining = (int) ceil(($this->monotonicDeadline - $this->clock->monotonic()) / self::NANOSECONDS_PER_SECOND);

        if ($remaining < 1) {
            throw new RuntimeException('The filesystem operation timed out before it could start.');
        }

        return $remaining;
    }

    public function recoveryAfter(int $grace): Carbon
    {
        // Alarm enforcement and persisted timestamps have whole-second precision.
        return $this->expiresAt->copy()->addSeconds($grace)->ceilSecond()
            ->addSeconds($this->fork ? self::FORK_ALARM_ROUNDING_MARGIN_SECONDS : 0);
    }
}
