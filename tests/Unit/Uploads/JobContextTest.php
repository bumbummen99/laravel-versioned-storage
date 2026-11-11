<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Policies\UploadContext;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

afterEach(function (): void {
    Mockery::close();
});

it('uses the newest asynchronous job while ignoring nested synchronous jobs', function () {
    $context = new UploadContext(new Repository(['laravel-versioned-storage' => ['upload_timeout' => 60]]));
    $context->commandStarting(new CommandStarting('queue:work', new ArgvInput(['artisan', '--timeout=60']), new NullOutput));
    $outer = Mockery::mock(Job::class);
    $outer->shouldReceive('timeout')->andReturn(11);
    $inner = Mockery::mock(Job::class);
    $inner->shouldReceive('timeout')->andReturn(23);
    $sync = Mockery::mock(SyncJob::class);

    $context->jobProcessing(new JobProcessing('testing', $outer));
    $context->jobProcessing(new JobProcessing('testing', $inner));
    $context->jobProcessing(new JobProcessing('sync', $sync));
    expect($context->execution())->toBe(['timeout' => 23, 'fork' => false]);
    $context->jobFinished(new JobProcessed('sync', $sync));
    expect($context->execution())->toBe(['timeout' => 23, 'fork' => false]);
    $context->jobFinished(new JobExceptionOccurred('testing', $inner, new RuntimeException('job failed')));
    expect($context->execution())->toBe(['timeout' => 11, 'fork' => false]);
});

it('removes only the most recent matching job when events finish out of order', function () {
    $context = new UploadContext(new Repository(['laravel-versioned-storage' => ['upload_timeout' => 60]]));
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

it('removes an older matching job without disturbing a newer different job', function () {
    $context = new UploadContext(new Repository(['laravel-versioned-storage' => ['upload_timeout' => 60]]));
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

it('ignores a synchronous job without inventing a queue worker', function () {
    $context = new UploadContext(new Repository(['laravel-versioned-storage' => ['upload_timeout' => 31]]));
    $sync = Mockery::mock(SyncJob::class);
    $sync->shouldNotReceive('timeout');

    $context->jobProcessing(new JobProcessing('sync', $sync));

    expect($context->execution())->toBe(['timeout' => 31, 'fork' => true]);
    $context->jobFinished(new JobProcessed('sync', $sync));
    expect($context->execution())->toBe(['timeout' => 31, 'fork' => true]);
});

it('uses runtime configuration when a worker is idle rather than its job timeout', function () {
    $context = new UploadContext(new Repository(['laravel-versioned-storage' => ['upload_timeout' => 31]]));
    $context->commandStarting(new CommandStarting(
        'queue:work', new ArgvInput(['artisan', '--timeout=17']), new NullOutput,
    ));

    expect($context->execution())->toBe(['timeout' => 31, 'fork' => true]);
});
