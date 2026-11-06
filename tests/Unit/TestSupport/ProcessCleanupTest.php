<?php

declare(strict_types=1);

use SkyRaptor\LaravelVersionedStorage\Tests\Support\ProcessCleanup;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Mockery::close();
});

it('attempts every process and retains resources when one remains active', function () {
    $first = Mockery::mock(Process::class);
    $first->shouldReceive('stop')->once()->with(1.0)->andThrow(new RuntimeException('stop refused'));
    $first->shouldReceive('isRunning')->once()->andReturn(true);
    $second = Mockery::mock(Process::class);
    $second->shouldReceive('stop')->once()->with(1.0)->andReturn(0);
    $second->shouldReceive('isRunning')->once()->andReturn(false);
    $deleted = false;

    expect(function () use ($first, $second, &$deleted): void {
        ProcessCleanup::run([$first, $second], function () use (&$deleted): void {
            $deleted = true;
        });
    })->toThrow(RuntimeException::class, 'stop refused');
    expect($deleted)->toBeFalse();
});

it('removes resources after all processes stop while still reporting stop errors', function () {
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('stop')->once()->with(1.0)->andThrow(new RuntimeException('stop reported failure'));
    $process->shouldReceive('isRunning')->once()->andReturn(false);
    $deleted = false;

    expect(function () use ($process, &$deleted): void {
        ProcessCleanup::run([$process], function () use (&$deleted): void {
            $deleted = true;
        });
    })->toThrow(RuntimeException::class, 'stop reported failure');
    expect($deleted)->toBeTrue();
});

it('reports resource removal failure', function () {
    expect(fn () => ProcessCleanup::run([], fn () => throw new RuntimeException('removal refused')))
        ->toThrow(RuntimeException::class, 'removal refused');
});
