<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Fork\Exceptions\CouldNotManageTask;
use Spatie\Fork\Fork;
use Throwable;

class ForkUpload
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $task
     * @return TResult
     */
    public function run(Closure $task, int $seconds): mixed
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('The forked upload time limit must be at least one second.');
        }

        if (
            ! in_array(PHP_SAPI, ['cli', 'cli-server'], true)
            || PHP_OS_FAMILY === 'Windows'
            || ! extension_loaded('pcntl')
            || ! extension_loaded('posix')
            || ! extension_loaded('sockets')
            || ! function_exists('pcntl_fork')
        ) {
            throw new RuntimeException('Forked uploads require CLI or cli-server on Unix with the PCNTL, POSIX and sockets extensions.');
        }

        $asynchronous = pcntl_async_signals();
        $handlers = [];
        foreach ([SIGQUIT, SIGTERM, SIGINT] as $signal) {
            $handlers[$signal] = pcntl_signal_get_handler($signal);
        }

        try {
            $results = Fork::new()
                ->before(child: function () use ($seconds): void {
                    // The OS, not a PHP callback, must enforce the deadline even during native IO.
                    pcntl_signal(SIGALRM, SIG_DFL);
                    pcntl_sigprocmask(SIG_UNBLOCK, [SIGALRM]);
                    pcntl_alarm($seconds);
                })
                ->run(function () use ($task): array|Throwable {
                    try {
                        return ['result' => $task()];
                    } catch (Throwable $exception) {
                        return $exception;
                    }
                });
        } catch (CouldNotManageTask $exception) {
            throw new RuntimeException("Forked upload timed out or exited abnormally (limit: {$seconds} seconds).", previous: $exception);
        } finally {
            foreach ($handlers as $signal => $handler) {
                $this->restoreSignalHandler($signal, $handler);
            }
            pcntl_async_signals($asynchronous);
        }

        $result = $results[0] ?? null;

        if ($result instanceof Throwable) {
            throw $result;
        }

        // A signal-killed task can produce empty output without Spatie reporting an error.
        if (! is_array($result) || ! array_key_exists('result', $result)) {
            throw new RuntimeException("Forked upload timed out or exited abnormally (limit: {$seconds} seconds).");
        }

        return $result['result'];
    }

    private function restoreSignalHandler(int $signal, mixed $handler): void
    {
        if (! is_int($handler) && ! is_callable($handler)) {
            throw new RuntimeException("Unable to restore the handler for signal [{$signal}].");
        }

        pcntl_signal($signal, $handler);
    }
}
