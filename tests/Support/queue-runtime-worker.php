<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Policies\UploadContext;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

require __DIR__.'/autoload.php';

$job = Mockery::mock(Job::class);
$job->shouldReceive('timeout')->andReturn(null);
$context = new UploadContext;
$context->commandStarting(new CommandStarting('queue:work', new ArgvInput(['artisan', '--timeout=10']), new NullOutput));
$context->jobProcessing(new JobProcessing('testing', $job));
$context->execution();
