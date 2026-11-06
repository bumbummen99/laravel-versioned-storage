<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Uploads;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use RuntimeException;

class UploadContext implements UploadExecutionPolicy
{
    /** @var list<array{command: string, timeout: int, once: bool}> */
    private array $workers = [];

    /** @var list<Job> */
    private array $jobs = [];

    public function commandStarting(CommandStarting $event): void
    {
        if (! in_array($event->command, ['queue:work', 'horizon:work'], true)) {
            return;
        }

        $timeout = $event->input->getParameterOption('--timeout', 60);
        $this->workers[] = [
            'command' => $event->command,
            'timeout' => filter_var($timeout, FILTER_VALIDATE_INT) === false
                ? throw new RuntimeException('The worker timeout must be an integer.')
                : (int) $timeout,
            'once' => $event->input->hasParameterOption('--once'),
        ];
    }

    public function commandFinished(CommandFinished $event): void
    {
        if (in_array($event->command, ['queue:work', 'horizon:work'], true)) {
            array_pop($this->workers);
        }
    }

    public function jobProcessing(JobProcessing $event): void
    {
        $this->jobs[] = $event->job;
    }

    public function jobFinished(JobProcessed|JobExceptionOccurred $event): void
    {
        foreach (array_reverse(array_keys($this->jobs)) as $key) {
            if ($this->jobs[$key] === $event->job) {
                array_splice($this->jobs, $key, 1);

                break;
            }
        }
    }

    /** @return array{timeout: int, fork: bool} */
    public function execution(): array
    {
        $job = null;
        foreach (array_reverse($this->jobs) as $candidate) {
            if (! $candidate instanceof SyncJob) {
                $job = $candidate;

                break;
            }
        }

        if ($job !== null) {
            $worker = $this->workers[array_key_last($this->workers)] ?? null;

            if ($worker === null) {
                throw new RuntimeException('An upload job must run in a recognized queue:work or horizon:work command.');
            }
            $timeout = $this->positiveTimeout($job->timeout() ?? $worker['timeout']);

            if (! $worker['once'] && (! function_exists('pcntl_alarm') || ! function_exists('posix_kill'))) {
                throw new RuntimeException('Queue upload timeouts require PCNTL and POSIX process termination support.');
            }

            return ['timeout' => $timeout, 'fork' => $worker['once']];
        }

        $timeout = $this->positiveTimeout(config('laravel-versioned-storage.upload_timeout'));

        return match ($this->sapi()) {
            'fpm-fcgi' => ['timeout' => $timeout, 'fork' => false],
            'cli', 'cli-server' => ['timeout' => $timeout, 'fork' => true],
            default => throw new RuntimeException('Uploads require PHP-FPM, CLI, or the PHP development server.'),
        };
    }

    protected function sapi(): string
    {
        return PHP_SAPI;
    }

    private function positiveTimeout(mixed $value): int
    {
        $timeout = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($timeout === false) {
            throw new RuntimeException('The effective upload timeout must be a positive number of seconds.');
        }

        return $timeout;
    }
}
