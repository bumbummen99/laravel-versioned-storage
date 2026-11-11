<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Policies\UploadContext;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;

it('requires both alarm and process termination support for a long-running queue worker', function (string $disabled) {
    $context = new UploadContext(new Repository);
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
})->with([
    'alarm support disabled' => 'pcntl_alarm',
    'process termination disabled' => 'posix_kill',
]);
