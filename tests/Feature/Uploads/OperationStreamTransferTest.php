<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\ForkUpload;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestUploadClock;
use SkyRaptor\LaravelVersionedStorage\UploadClock;
use SkyRaptor\LaravelVersionedStorage\UploadDeadline;

it('rebuilds the fork disk and arms its alarm using the remaining operation budget', function () {
    $clock = new TestUploadClock(now());
    $deadline = new UploadDeadline(30, true, $clock);
    $clock->nanoseconds = 2_250_000_000;
    $disk = Storage::disk('local');
    $fork = Mockery::mock(ForkUpload::class);
    $fork->shouldReceive('run')->once()->with(Mockery::type(Closure::class), 30)
        ->andReturnUsing(fn (Closure $callback) => $callback());
    app()->instance(ForkUpload::class, $fork);
    $original = Mockery::mock(FilesystemOperator::class);
    $original->shouldNotReceive('write');

    try {
        $result = (new FilesystemOperationExecutor)->execute(
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

it('transfers a forked stream into a rewound buffer and closes the source', function () {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'stream contents');
    rewind($stream);
    $fork = Mockery::mock(ForkUpload::class);
    $fork->shouldReceive('run')->once()->andReturnUsing(fn (Closure $callback) => $callback());
    app()->instance(ForkUpload::class, $fork);
    $disk = Storage::disk('local');
    $buffer = null;

    try {
        $buffer = (new FilesystemOperationExecutor)->readStream(
            fn (FilesystemOperator $files) => $stream,
            $disk->getDriver(), ['driver' => 'local', 'root' => $disk->path('')], new UploadDeadline(30, true, new UploadClock), true,
        );
        expect(is_resource($stream))->toBeFalse()
            ->and(is_resource($buffer))->toBeTrue()
            ->and(stream_get_contents($buffer))->toBe('stream contents');
    } finally {
        pcntl_alarm(0);

        if (is_resource($stream)) {
            fclose($stream);
        }

        if (is_resource($buffer)) {
            fclose($buffer);
        }
    }
});

it('closes the transfer buffer when the source read fails', function () {
    $failure = new RuntimeException('Source read failed');
    $buffer = null;
    $executor = Mockery::mock(FilesystemOperationExecutor::class)->makePartial();
    $executor->shouldReceive('execute')->once()->andReturnUsing(function (Closure $callback) use (&$buffer): void {
        $buffer = (new ReflectionFunction($callback))->getStaticVariables()['buffer'];
        $callback(Mockery::mock(FilesystemOperator::class));
    });

    try {
        expect(fn () => $executor->readStream(
            fn (FilesystemOperator $files) => throw $failure,
            Storage::disk('local')->getDriver(), [], new UploadDeadline(30, true, new UploadClock), true,
        ))->toThrow($failure);
        expect(is_resource($buffer))->toBeFalse();
    } finally {
        if (is_resource($buffer)) {
            fclose($buffer);
        }
    }
});
