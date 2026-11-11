<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\ForkUpload;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadDeadline;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestUploadClock;

it('fails explicitly when the process cannot allocate a temporary read buffer', function () {
    $limits = posix_getrlimit();

    if ($limits === false) {
        throw new RuntimeException('Unable to read the process resource limits.');
    }
    $softLimit = $limits['soft openfiles'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['soft openfiles'];
    $hardLimit = $limits['hard openfiles'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['hard openfiles'];
    $executor = new FilesystemOperationExecutor;
    $filesystem = Storage::disk('local')->getDriver();
    $deadline = new UploadDeadline(30, true, new UploadClock);
    $failure = null;

    try {
        $limited = posix_setrlimit(POSIX_RLIMIT_NOFILE, 0, $hardLimit);
        $executor->readStream(
            fn () => throw new LogicException('No filesystem read may start without a buffer.'),
            $filesystem, [], $deadline, true,
        );
    } catch (RuntimeException $exception) {
        $failure = $exception;
    } finally {
        $restored = posix_setrlimit(POSIX_RLIMIT_NOFILE, $softLimit, $hardLimit);
    }

    expect($limited)->toBeTrue()
        ->and($restored)->toBeTrue()
        ->and($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($failure?->getMessage())->toBe('Unable to allocate a temporary upload read stream.');
});

it('rejects a failed stream copy even when the temporary buffer can still be flushed', function () {
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
    $fork = Mockery::mock(ForkUpload::class);
    $fork->shouldReceive('run')->once()->andReturnUsing(fn (Closure $callback) => $callback());
    app()->instance(ForkUpload::class, $fork);
    $disk = Storage::disk('local');

    try {
        expect(fn () => (new FilesystemOperationExecutor)->readStream(
            fn () => $stream,
            $disk->getDriver(), ['driver' => 'local', 'root' => $disk->path('')], new UploadDeadline(30, true, new UploadClock), true,
        ))->toThrow(RuntimeException::class, 'Unable to transfer the filesystem read stream.');
        expect(is_resource($stream))->toBeFalse();
    } finally {
        pcntl_alarm(0);

        if (is_resource($stream)) {
            fclose($stream);
        }
        stream_wrapper_unregister('versioned-failed-read');
    }
});

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
