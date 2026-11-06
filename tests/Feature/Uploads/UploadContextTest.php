<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;
use SkyRaptor\LaravelVersionedStorage\Uploads\UploadContext;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;

it('requires both alarm and process termination support for a long-running queue worker', function (string $disabled) {
    $context = new UploadContext;
    $context->commandStarting(new CommandStarting('queue:work', new ArgvInput(['artisan', '--timeout=10']), new NullOutput));
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn(null);
    $context->jobProcessing(new JobProcessing('testing', $job));
    expect($context->execution())->toBe(['timeout' => 10, 'fork' => false]);

    $process = new Process([
        PHP_BINARY, '-d', "disable_functions={$disabled}", TestPaths::root('tests/Support/queue-runtime-worker.php'),
    ]);
    $process->setTimeout(5);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('Queue upload timeouts require PCNTL and POSIX process termination support.');
})->with(['pcntl_alarm', 'posix_kill']);

it('uses the newest worker and restores its parent when it finishes', function () {
    $context = new UploadContext;
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
    expect(fn () => $context->execution())->toThrow(RuntimeException::class, 'recognized queue:work or horizon:work');
});

it('does not treat unrelated commands as workers or let them pop the current worker', function () {
    $context = new UploadContext;
    $input = new ArgvInput(['artisan', '--timeout=17', '--once']);
    $output = new NullOutput;
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn(null);
    $context->jobProcessing(new JobProcessing('testing', $job));
    $context->commandStarting(new CommandStarting('list', $input, $output));
    expect(fn () => $context->execution())->toThrow(RuntimeException::class, 'recognized queue:work or horizon:work');

    $context->commandStarting(new CommandStarting('queue:work', $input, $output));
    $context->commandStarting(new CommandStarting('list', $input, $output));
    $context->commandFinished(new CommandFinished('list', $input, $output, 0));
    expect($context->execution())->toBe(['timeout' => 17, 'fork' => true]);
});

it('rejects invalid worker timeouts without leaving a worker on the stack', function () {
    $context = new UploadContext;
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn(null);
    $context->jobProcessing(new JobProcessing('testing', $job));

    expect(fn () => $context->commandStarting(new CommandStarting(
        'queue:work', new ArgvInput(['artisan', '--timeout=invalid']), new NullOutput,
    )))->toThrow(RuntimeException::class, 'worker timeout must be an integer');
    expect(fn () => $context->execution())->toThrow(RuntimeException::class, 'recognized queue:work or horizon:work');
});

it('uses the newest asynchronous job while ignoring nested synchronous jobs', function () {
    $context = new UploadContext;
    $context->commandStarting(new CommandStarting('queue:work', new ArgvInput(['artisan', '--timeout=60']), new NullOutput));
    $outer = Mockery::mock(Job::class);
    $outer->shouldReceive('timeout')->andReturn(11);
    $inner = Mockery::mock(Job::class);
    $inner->shouldReceive('timeout')->andReturn(23);
    $sync = new SyncJob(app(), '{}', 'sync', 'default');

    $context->jobProcessing(new JobProcessing('testing', $outer));
    $context->jobProcessing(new JobProcessing('testing', $inner));
    $context->jobProcessing(new JobProcessing('sync', $sync));
    expect($context->execution())->toBe(['timeout' => 23, 'fork' => false]);
    $context->jobFinished(new JobProcessed('sync', $sync));
    $context->jobFinished(new JobExceptionOccurred('testing', $inner, new RuntimeException('job failed')));
    expect($context->execution())->toBe(['timeout' => 11, 'fork' => false]);
});

it('removes only the most recent matching job when events finish out of order', function () {
    $context = new UploadContext;
    $context->commandStarting(new CommandStarting('queue:work', new ArgvInput(['artisan', '--once']), new NullOutput));
    $outer = Mockery::mock(Job::class);
    $outer->shouldReceive('timeout')->andReturn(11);
    $inner = Mockery::mock(Job::class);
    $inner->shouldReceive('timeout')->andReturn(23);
    $unknown = Mockery::mock(Job::class);

    $context->jobProcessing(new JobProcessing('testing', $outer));
    $context->jobProcessing(new JobProcessing('testing', $inner));
    $context->jobProcessing(new JobProcessing('testing', $outer));
    $context->jobFinished(new JobProcessed('testing', $outer));
    expect($context->execution())->toBe(['timeout' => 23, 'fork' => true]);
    $context->jobFinished(new JobProcessed('testing', $unknown));
    expect($context->execution())->toBe(['timeout' => 23, 'fork' => true]);
    $context->jobFinished(new JobProcessed('testing', $inner));
    expect($context->execution())->toBe(['timeout' => 11, 'fork' => true]);
    $context->jobFinished(new JobProcessed('testing', $outer));
    expect($context->execution())->toBe(['timeout' => 60, 'fork' => true]);
});

it('rejects disabled job timeouts even when the worker has a positive timeout', function (mixed $timeout) {
    $context = new UploadContext;
    $context->commandStarting(new CommandStarting('queue:work', new ArgvInput(['artisan', '--timeout=17']), new NullOutput));
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn($timeout);
    $context->jobProcessing(new JobProcessing('testing', $job));

    expect(fn () => $context->execution())->toThrow(RuntimeException::class, 'positive number of seconds');
})->with([0, -1, 'invalid']);

it('defaults a worker without timeout options to sixty seconds and non-forked execution', function () {
    $context = new UploadContext;
    $context->commandStarting(new CommandStarting('queue:work', new ArgvInput(['artisan']), new NullOutput));
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn(null);
    $context->jobProcessing(new JobProcessing('testing', $job));

    expect($context->execution())->toBe(['timeout' => 60, 'fork' => false]);
});

it('removes an older matching job without disturbing a newer different job', function () {
    $context = new UploadContext;
    $context->commandStarting(new CommandStarting('queue:work', new ArgvInput(['artisan', '--once']), new NullOutput));
    $finished = Mockery::mock(Job::class);
    $finished->shouldReceive('timeout')->andReturn(11);
    $current = Mockery::mock(Job::class);
    $current->shouldReceive('timeout')->andReturn(23);
    $context->jobProcessing(new JobProcessing('testing', $finished));
    $context->jobProcessing(new JobProcessing('testing', $current));

    $context->jobFinished(new JobProcessed('testing', $finished));
    expect($context->execution())->toBe(['timeout' => 23, 'fork' => true]);
    $context->jobFinished(new JobProcessed('testing', $current));
    expect($context->execution())->toBe(['timeout' => 60, 'fork' => true]);
});
