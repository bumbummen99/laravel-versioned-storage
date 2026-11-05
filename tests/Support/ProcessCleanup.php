<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Tests\Support;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class ProcessCleanup
{
    /** @param list<Process> $processes */
    public static function run(array $processes, Closure $removeResources): void
    {
        $errors = [];
        $active = false;

        foreach ($processes as $process) {
            try {
                $process->stop(1.0);
            } catch (Throwable $exception) {
                $errors[] = $exception;
            }

            try {
                if ($process->isRunning()) {
                    $active = true;
                    $errors[] = new RuntimeException('An owned process is still running; temporary resources were retained.');
                }
            } catch (Throwable $exception) {
                $active = true;
                $errors[] = $exception;
            }
        }

        if (! $active) {
            try {
                $removeResources();
            } catch (Throwable $exception) {
                $errors[] = $exception;
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(
                "Fixture cleanup failed:\n".implode("\n", array_map(
                    static fn (Throwable $exception): string => $exception->getMessage(),
                    $errors,
                )),
                previous: $errors[0],
            );
        }
    }
}
