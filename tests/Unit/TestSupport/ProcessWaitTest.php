<?php

declare(strict_types=1);

use SkyRaptor\LaravelVersionedStorage\Tests\Support\ProcessWait;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

it('accepts readiness immediately', function () {
    ProcessWait::until(fn (): bool => true, [], 0.1);
    expect(true)->toBeTrue();
});

it('stops polling as soon as readiness is reported', function () {
    $polls = 0;
    ProcessWait::until(function () use (&$polls): bool {
        return ++$polls === 2;
    }, [], 1);

    expect($polls)->toBe(2);
});

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

it('reports a bounded readiness failure', function () {
    expect(fn () => ProcessWait::until(fn (): bool => false, [], 0.02, fn (): string => 'fixture logs'))
        ->toThrow(RuntimeException::class, 'fixture logs');
});

it('reports early process exit with its output', function () {
    $process = new Process([PHP_BINARY, '-r', 'fwrite(STDERR, "startup failed");']);
    $process->run();
    expect(fn () => ProcessWait::until(fn (): bool => false, [$process], 1))
        ->toThrow(RuntimeException::class, 'startup failed');
});

it('does not allow a blocking observation to finish early', function () {
    expect(fn () => ProcessWait::observe(fn (): bool => false, [], 0.02))
        ->toThrow(RuntimeException::class, 'Observation failed');
});

it('observes a condition for the entire required interval', function () {
    $started = hrtime(true);
    ProcessWait::observe(fn (): bool => true, [], 0.03);
    expect((hrtime(true) - $started) / 1_000_000_000)->toBeGreaterThanOrEqual(0.03);
});

it('rejects an unbounded or nonpositive wait', function (float $seconds) {
    expect(fn () => ProcessWait::until(fn (): bool => true, [], $seconds))
        ->toThrow(InvalidArgumentException::class, 'finite positive duration');
})->with([0.0, -1.0, INF, NAN]);
