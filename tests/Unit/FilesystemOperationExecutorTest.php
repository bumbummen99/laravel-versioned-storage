<?php

declare(strict_types=1);

use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\UploadClock;
use SkyRaptor\LaravelVersionedStorage\UploadDeadline;

afterEach(function (): void {
    Mockery::close();
});

it('preserves operation result shapes in synchronous execution', function (mixed $result) {
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $executor = new FilesystemOperationExecutor;

    expect($executor->execute(
        fn (FilesystemOperator $operator): mixed => $operator === $filesystem ? $result : throw new LogicException('Wrong operator'),
        $filesystem,
        [],
        new UploadDeadline(60, false, new UploadClock),
        false,
    ))->toBe($result);
})->with([false, true, 12, 'contents', [['one', 'two']], null]);

it('returns the original stream for synchronous reads', function () {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'contents');
    rewind($stream);
    $filesystem = Mockery::mock(FilesystemOperator::class);

    try {
        $result = (new FilesystemOperationExecutor)->readStream(
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
