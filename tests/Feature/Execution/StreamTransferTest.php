<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\ForkUpload;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadDeadline;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestUploadClock;

it('rebuilds the fork disk and arms its alarm using the remaining operation budget', function () {
    $clock = new TestUploadClock(now());
    $deadline = new UploadDeadline(30, true, $clock);
    $clock->advanceMonotonicSeconds(2.25);
    $disk = Storage::disk('local');
    $fork = Mockery::mock(ForkUpload::class);
    $fork->shouldReceive('run')->once()->with(Mockery::type(Closure::class), 30)
        ->andReturnUsing(fn (Closure $callback) => $callback());
    app()->instance(ForkUpload::class, $fork);
    $original = Mockery::mock(FilesystemOperator::class);
    $original->shouldNotReceive('write');

    try {
        $result = app(FilesystemOperationExecutor::class)->execute(
            function (FilesystemOperator $files): int {
                $files->write('forked', 'contents');

                return pcntl_alarm(0);
            },
            $original, ['driver' => 'local', 'root' => $disk->path('')], $deadline, true,
        );
        expect($result)->toBe(28);
        $disk->assertExists('forked');
        expect($disk->get('forked'))->toBe('contents');
    } finally {
        pcntl_alarm(0);
    }
});
