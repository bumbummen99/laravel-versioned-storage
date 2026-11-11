<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadDeadline;

it('fails explicitly when the process cannot allocate a temporary read buffer', function () {
    $limits = posix_getrlimit();

    if ($limits === false) {
        throw new RuntimeException('Unable to read the process resource limits.');
    }

    $softLimit = $limits['soft openfiles'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['soft openfiles'];
    $hardLimit = $limits['hard openfiles'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['hard openfiles'];
    $executor = app(FilesystemOperationExecutor::class);
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
