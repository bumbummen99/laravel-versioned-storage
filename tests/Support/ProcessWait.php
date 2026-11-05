<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Tests\Support;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class ProcessWait
{
    private const int POLL_MICROSECONDS = 10_000;

    /** @param list<Process> $processes */
    public static function until(Closure $condition, array $processes, float $seconds, ?Closure $logs = null): void
    {
        $deadline = self::deadline($seconds);

        do {
            if ($condition()) {
                return;
            }
            self::checkProcesses($processes, $logs);

            if (hrtime(true) >= $deadline) {
                self::fail('Readiness timed out.', $processes, $logs);
            }
            usleep(self::POLL_MICROSECONDS);
        } while (true);
    }

    /** @param list<Process> $processes */
    public static function observe(Closure $condition, array $processes, float $seconds, ?Closure $logs = null): void
    {
        $deadline = self::deadline($seconds);

        do {
            if (! $condition()) {
                self::fail('Observation failed before its required duration.', $processes, $logs);
            }
            self::checkProcesses($processes, $logs);

            if (hrtime(true) >= $deadline) {
                return;
            }
            usleep(self::POLL_MICROSECONDS);
        } while (true);
    }

    private static function deadline(float $seconds): int
    {
        if (! is_finite($seconds) || $seconds <= 0) {
            throw new InvalidArgumentException('A process wait must have a finite positive duration.');
        }

        return hrtime(true) + (int) ceil($seconds * 1_000_000_000);
    }

    /** @param list<Process> $processes */
    private static function checkProcesses(array $processes, ?Closure $logs): void
    {
        foreach ($processes as $process) {
            try {
                $process->checkTimeout();
            } catch (ProcessTimedOutException $exception) {
                self::fail('Process timed out.', $processes, $logs, $exception);
            }

            if (! $process->isRunning()) {
                self::fail('Process exited before the wait completed.', $processes, $logs);
            }
        }
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
