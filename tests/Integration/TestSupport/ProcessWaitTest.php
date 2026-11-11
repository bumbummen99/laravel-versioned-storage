<?php

declare(strict_types=1);

use SkyRaptor\LaravelVersionedStorage\Tests\Support\ProcessWait;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

it('retains the timeout cause and worker output', function () {
    $process = new Process([PHP_BINARY, '-r', 'echo "ready"; fflush(STDOUT); sleep(30);']);
    $process->setTimeout(5);

    try {
        $process->start();
        ProcessWait::until(fn (): bool => str_contains($process->getOutput(), 'ready'), [$process], 5);
        $process->setTimeout(0.02);

        expect(fn () => ProcessWait::until(fn (): bool => false, [$process], 1))
            ->toThrow(function (RuntimeException $exception): void {
                expect($exception->getMessage())->toContain('Process timed out.', 'ready')
                    ->and($exception->getPrevious())->toBeInstanceOf(ProcessTimedOutException::class);
            });
    } finally {
        $process->stop();
    }
});

it('reports early process exit with its output', function () {
    $process = new Process([PHP_BINARY, '-r', 'fwrite(STDERR, "startup failed");']);

    $process->run();

    expect(fn () => ProcessWait::until(fn (): bool => false, [$process], 1))
        ->toThrow(RuntimeException::class, 'startup failed');
});
