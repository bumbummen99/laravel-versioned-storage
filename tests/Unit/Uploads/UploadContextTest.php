<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Policies\UploadContext;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

afterEach(function (): void {
    Mockery::close();
});

it('uses the newest worker and restores its parent when it finishes', function () {
    $context = new UploadContext(new Repository);
    $outer = new ArgvInput(['artisan', '--timeout=17']);
    $inner = new ArgvInput(['artisan', '--timeout=29', '--once']);
    $output = new NullOutput;
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn(null);

    $context->commandStarting(new CommandStarting('queue:work', $outer, $output));
    $context->jobProcessing(new JobProcessing('testing', $job));
    expect($context->execution())->toBe(['timeout' => 17, 'fork' => false]);

    $context->commandStarting(new CommandStarting('horizon:work', $inner, $output));
    expect($context->execution())->toBe(['timeout' => 29, 'fork' => true]);

    $context->commandFinished(new CommandFinished('horizon:work', $inner, $output, 0));
    expect($context->execution())->toBe(['timeout' => 17, 'fork' => false]);

    $context->commandFinished(new CommandFinished('queue:work', $outer, $output, 0));
    expect(fn () => $context->execution())
        ->toThrow(RuntimeException::class, 'recognized queue:work or horizon:work');
});

it('does not treat unrelated commands as workers or let them pop the current worker', function () {
    $context = new UploadContext(new Repository);
    $input = new ArgvInput(['artisan', '--timeout=17', '--once']);
    $output = new NullOutput;
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn(null);
    $context->jobProcessing(new JobProcessing('testing', $job));

    $context->commandStarting(new CommandStarting('list', $input, $output));
    expect(fn () => $context->execution())
        ->toThrow(RuntimeException::class, 'recognized queue:work or horizon:work');

    $context->commandStarting(new CommandStarting('queue:work', $input, $output));
    $context->commandStarting(new CommandStarting('list', $input, $output));
    $context->commandFinished(new CommandFinished('list', $input, $output, 0));

    expect($context->execution())->toBe(['timeout' => 17, 'fork' => true]);
});

it('rejects invalid worker timeouts without leaving a worker on the stack', function (string $timeout) {
    $context = new UploadContext(new Repository);
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn(null);
    $context->jobProcessing(new JobProcessing('testing', $job));

    expect(fn () => $context->commandStarting(new CommandStarting(
        'queue:work', new ArgvInput(['artisan', '--timeout='.$timeout]), new NullOutput,
    )))->toThrow(RuntimeException::class, 'worker timeout must be an integer');
    expect(fn () => $context->execution())
        ->toThrow(RuntimeException::class, 'recognized queue:work or horizon:work');
})->with(['text' => 'invalid', 'fractional seconds' => '1.5', 'empty option' => '', 'integer overflow' => '999999999999999999999999']);

it('rejects disabled job timeouts even when the worker has a positive timeout', function (mixed $timeout) {
    $context = new UploadContext(new Repository);
    $context->commandStarting(new CommandStarting('queue:work', new ArgvInput(['artisan', '--timeout=17']), new NullOutput));
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn($timeout);
    $context->jobProcessing(new JobProcessing('testing', $job));

    expect(fn () => $context->execution())->toThrow(RuntimeException::class, 'positive number of seconds');
})->with([
    'zero seconds' => 0,
    'negative seconds' => -1,
    'not a number' => 'invalid',
]);

it('defaults a worker without timeout options to sixty seconds and non-forked execution', function () {
    $context = new UploadContext(new Repository);
    $context->commandStarting(new CommandStarting('queue:work', new ArgvInput(['artisan']), new NullOutput));
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn(null);
    $context->jobProcessing(new JobProcessing('testing', $job));

    expect($context->execution())->toBe(['timeout' => 60, 'fork' => false]);
});

it('uses a job override independently of the worker fallback', function (string $worker, int $jobTimeout) {
    $context = new UploadContext(new Repository);
    $context->commandStarting(new CommandStarting(
        'queue:work', new ArgvInput(['artisan', '--once', '--timeout='.$worker]), new NullOutput,
    ));
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn($jobTimeout);
    $context->jobProcessing(new JobProcessing('testing', $job));

    expect($context->execution())->toBe(['timeout' => $jobTimeout, 'fork' => true]);
})->with([
    'shorter job limit' => ['60', 1],
    'longer job limit' => ['1', 60],
    'disabled worker limit' => ['0', 17],
    'negative worker limit' => ['-1', 17],
]);

it('rejects a disabled worker fallback when the job has no override', function (string $timeout) {
    $context = new UploadContext(new Repository);
    $context->commandStarting(new CommandStarting(
        'queue:work', new ArgvInput(['artisan', '--once', '--timeout='.$timeout]), new NullOutput,
    ));
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn(null);
    $context->jobProcessing(new JobProcessing('testing', $job));

    expect(fn () => $context->execution())->toThrow(RuntimeException::class, 'positive number of seconds');
})->with(['zero seconds' => '0', 'negative seconds' => '-1']);

it('leaves the current worker intact when a nested worker has invalid options', function () {
    $context = new UploadContext(new Repository);
    $context->commandStarting(new CommandStarting(
        'queue:work', new ArgvInput(['artisan', '--once', '--timeout=17']), new NullOutput,
    ));
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn(null);
    $context->jobProcessing(new JobProcessing('testing', $job));

    expect(fn () => $context->commandStarting(new CommandStarting(
        'horizon:work', new ArgvInput(['artisan', '--timeout=invalid']), new NullOutput,
    )))->toThrow(RuntimeException::class, 'worker timeout must be an integer');
    expect($context->execution())->toBe(['timeout' => 17, 'fork' => true]);
});
