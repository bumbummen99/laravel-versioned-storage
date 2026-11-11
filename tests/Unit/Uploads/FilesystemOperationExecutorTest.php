<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Carbon;
use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\ForkUpload;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadDeadline;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestUploadClock;

afterEach(function (): void {
    Mockery::close();
});

it('preserves operation result shapes in synchronous execution', function (mixed $result) {
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $executor = new FilesystemOperationExecutor(
        Mockery::mock(ForkUpload::class),
        Mockery::mock(FilesystemManager::class),
    );

    expect($executor->execute(
        function (FilesystemOperator $operator) use ($filesystem, $result): mixed {
            expect($operator)->toBe($filesystem);

            return $result;
        },
        $filesystem,
        [],
        new UploadDeadline(60, false, new UploadClock),
        false,
    ))->toBe($result);
})->with([
    'false' => false,
    'true' => true,
    'file size' => 12,
    'file contents' => 'contents',
    'file listing' => [['one', 'two']],
    'no return value' => null,
]);

it('returns the original stream for synchronous reads', function () {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'contents');
    rewind($stream);
    $filesystem = Mockery::mock(FilesystemOperator::class);

    try {
        $executor = new FilesystemOperationExecutor(
            Mockery::mock(ForkUpload::class),
            Mockery::mock(FilesystemManager::class),
        );
        $result = $executor->readStream(
            fn (FilesystemOperator $operator) => $stream,
            $filesystem,
            [],
            new UploadDeadline(60, false, new UploadClock),
            false,
        );
        expect($result)->toBe($stream)->and(stream_get_contents($result))->toBe('contents');
    } finally {
        fclose($stream);
    }
});

it('dispatches synchronous stream reads without requesting a fork', function () {
    $stream = fopen('php://temp', 'w+b');
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $deadline = new UploadDeadline(60, false, new UploadClock);
    $callback = fn (FilesystemOperator $operator) => $stream;
    $executor = Mockery::mock(FilesystemOperationExecutor::class)->makePartial();
    $executor->shouldReceive('execute')->once()->with($callback, $filesystem, [], $deadline, false)
        ->andReturn($stream);

    try {
        expect($executor->readStream($callback, $filesystem, [], $deadline, false))->toBe($stream);
    } finally {
        fclose($stream);
    }
});

it('rejects an expired operation before executing or supervising any IO', function (bool $fork) {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01'));
    $deadline = new UploadDeadline(10, $fork, $clock);
    $clock->advanceMonotonicSeconds(10);
    $supervisor = Mockery::mock(ForkUpload::class);
    $supervisor->shouldNotReceive('run');
    $manager = Mockery::mock(FilesystemManager::class);
    $manager->shouldNotReceive('build');
    $executor = new FilesystemOperationExecutor($supervisor, $manager);

    expect(fn () => $executor->execute(
        fn () => throw new LogicException('Expired IO must not start.'),
        Mockery::mock(FilesystemOperator::class), [], $deadline, $fork,
    ))->toThrow(RuntimeException::class, 'timed out before it could start');
})->with(['inline' => false, 'supervised' => true]);

it('propagates the exact synchronous filesystem exception without supervising it', function () {
    $failure = new LogicException('filesystem unavailable');
    $supervisor = Mockery::mock(ForkUpload::class);
    $supervisor->shouldNotReceive('run');
    $executor = new FilesystemOperationExecutor(
        $supervisor, Mockery::mock(FilesystemManager::class),
    );

    expect(fn () => $executor->execute(
        fn () => throw $failure,
        Mockery::mock(FilesystemOperator::class), [], new UploadDeadline(10, false, new UploadClock), false,
    ))->toThrow($failure);
});
