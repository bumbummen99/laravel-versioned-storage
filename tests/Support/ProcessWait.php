<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Tests\Support;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class ProcessWait
{
    private const int POLL_MICROSECONDS = 10_000;

    /** @param list<Process> $processes */
    public static function until(Closure $condition, array $processes, float $seconds, ?Closure $logs = null): void
    {
        self::wait($condition, $processes, $seconds, $logs, false);
    }

    /** @param list<Process> $processes */
    public static function observe(Closure $condition, array $processes, float $seconds, ?Closure $logs = null): void
    {
        self::wait($condition, $processes, $seconds, $logs, true);
    }

    /** @param list<Process> $processes */
    private static function wait(Closure $condition, array $processes, float $seconds, ?Closure $logs, bool $observe): void
    {
        if (! is_finite($seconds) || $seconds <= 0) {
            throw new InvalidArgumentException('A process wait must have a finite positive duration.');
        }
        $deadline = hrtime(true) + (int) ceil($seconds * 1_000_000_000);

        do {
            $satisfied = $condition();

            if (! $observe && $satisfied) {
                return;
            }

            if ($observe && ! $satisfied) {
                self::fail('Observation failed before its required duration.', $processes, $logs);
            }
            foreach ($processes as $process) {
                try {
                    $process->checkTimeout();
                } catch (Throwable $exception) {
                    self::fail('Process timed out.', $processes, $logs, $exception);
                }

                if (! $process->isRunning()) {
                    self::fail('Process exited before the wait completed.', $processes, $logs);
                }
            }

            if (hrtime(true) >= $deadline) {
                if ($observe) {
                    return;
                }
                self::fail('Readiness timed out.', $processes, $logs);
            }
            usleep(self::POLL_MICROSECONDS);
        } while (true);
    }

    /** @param list<Process> $processes */
    private static function fail(string $message, array $processes, ?Closure $logs, ?Throwable $previous = null): never
    {
        foreach ($processes as $process) {
            $message .= "\n".$process->getOutput().$process->getErrorOutput();
        }

        if ($logs !== null) {
            $message .= "\n".$logs();
        }

        throw new RuntimeException($message, previous: $previous);
    }
}
