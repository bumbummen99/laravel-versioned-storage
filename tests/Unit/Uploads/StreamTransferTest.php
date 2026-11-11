<?php

declare(strict_types=1);

use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadDeadline;

afterEach(function (): void {
    Mockery::close();
});

it('buffers a transferred read stream and closes its source before returning', function () {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'stream contents');
    rewind($stream);
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $executor = Mockery::mock(FilesystemOperationExecutor::class)->makePartial();
    $executor->shouldReceive('execute')->once()->andReturnUsing(
        fn (Closure $callback) => $callback($filesystem),
    );
    $buffer = null;

    try {
        $buffer = $executor->readStream(
            fn () => $stream, $filesystem, [], new UploadDeadline(30, true, new UploadClock), true,
        );

        expect(is_resource($stream))->toBeFalse()
            ->and(ftell($buffer))->toBe(0)
            ->and(stream_get_contents($buffer))->toBe('stream contents');
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (is_resource($buffer)) {
            fclose($buffer);
        }
    }
});

it('closes the transfer buffer when reading the source throws', function () {
    $failure = new RuntimeException('Source read failed');
    $buffer = null;
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $executor = Mockery::mock(FilesystemOperationExecutor::class)->makePartial();
    $executor->shouldReceive('execute')->once()->andReturnUsing(function (Closure $callback) use (&$buffer, $filesystem): void {
        $buffer = (new ReflectionFunction($callback))->getStaticVariables()['buffer'];
        $callback($filesystem);
    });

    try {
        expect(fn () => $executor->readStream(
            fn () => throw $failure, $filesystem, [], new UploadDeadline(30, true, new UploadClock), true,
        ))->toThrow($failure);
        expect(is_resource($buffer))->toBeFalse();
    } finally {
        if (is_resource($buffer)) {
            fclose($buffer);
        }
    }
});

it('rejects a failed copy even when the temporary buffer can still be flushed', function () {
    $wrapper = new class
    {
        public mixed $context;

        public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
        {
            return true;
        }

        public function stream_read(int $count): false
        {
            return false;
        }

        public function stream_eof(): bool
        {
            return false;
        }

        public function stream_stat(): array
        {
            return [];
        }
    };
    expect(stream_wrapper_register('versioned-failed-read', $wrapper::class))->toBeTrue();
    $stream = fopen('versioned-failed-read://source', 'rb');
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $executor = Mockery::mock(FilesystemOperationExecutor::class)->makePartial();
    $executor->shouldReceive('execute')->once()->andReturnUsing(
        fn (Closure $callback) => $callback($filesystem),
    );

    try {
        expect(fn () => $executor->readStream(
            fn () => $stream, $filesystem, [], new UploadDeadline(30, true, new UploadClock), true,
        ))->toThrow(RuntimeException::class, 'Unable to transfer the filesystem read stream.');
        expect(is_resource($stream))->toBeFalse();
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
        stream_wrapper_unregister('versioned-failed-read');
    }
});
