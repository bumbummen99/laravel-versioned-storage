<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\UploadOperation;

it('closes an unacknowledged stream and rejects subsequent execution with the original cause', function () {
    $stream = fopen('php://temp', 'w+b');
    $failure = new RuntimeException('Read acknowledgement failed');
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldReceive('readStream')->once()->with('report')->andReturn($stream);
    $filesystem->shouldNotReceive('read');
    $lifecycle = Mockery::mock();
    $lifecycle->shouldReceive('begin')->once();
    $lifecycle->shouldReceive('acknowledge')->once()->with('')->andThrow($failure);
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: fn (...$arguments) => $lifecycle->begin(...$arguments),
        acknowledgeOperation: fn (string $id) => $lifecycle->acknowledge($id),
    );

    try {
        expect(fn () => $operation->readStream(fn (FilesystemOperator $files) => $files->readStream('report'), $filesystem))
            ->toThrow($failure);
        expect(is_resource($stream))->toBeFalse();

        expect(fn () => $operation->run('read', fn (FilesystemOperator $files) => $files->read('report'), $filesystem))
            ->toThrow(function (RuntimeException $exception) use ($failure): void {
                expect($exception->getMessage())->toBe('The snapshot failed during a filesystem operation.')
                    ->and($exception->getPrevious())->toBe($failure);
            });
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
});

it('hands an acknowledged read stream to the caller without closing it', function () {
    $filesystem = Storage::disk('local')->getDriver();
    $filesystem->write('report', 'contents');
    $lifecycle = Mockery::mock();
    $lifecycle->shouldReceive('begin')->once();
    $lifecycle->shouldReceive('acknowledge')->once()->with('');
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: fn (...$arguments) => $lifecycle->begin(...$arguments),
        acknowledgeOperation: fn (string $id) => $lifecycle->acknowledge($id),
    );
    $stream = $operation->readStream(fn (FilesystemOperator $files) => $files->readStream('report'), $filesystem);

    try {
        expect(is_resource($stream))->toBeTrue()
            ->and(stream_get_contents($stream))->toBe('contents');
        $operation->assertHealthy();
    } finally {
        fclose($stream);
    }
});

it('does not acknowledge a failed stream execution and marks the operation unhealthy', function () {
    $failure = new RuntimeException('Read execution failed');
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldReceive('readStream')->once()->with('report')->andThrow($failure);
    $lifecycle = Mockery::mock();
    $lifecycle->shouldReceive('begin')->once();
    $lifecycle->shouldNotReceive('acknowledge');
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: fn (...$arguments) => $lifecycle->begin(...$arguments),
        acknowledgeOperation: fn (string $id) => $lifecycle->acknowledge($id),
    );

    expect(fn () => $operation->readStream(fn (FilesystemOperator $files) => $files->readStream('report'), $filesystem))
        ->toThrow($failure);
    expect(fn () => $operation->assertHealthy())
        ->toThrow(RuntimeException::class, 'The snapshot failed during a filesystem operation.');
});

it('does not execute or acknowledge when beginning an operation fails', function () {
    $failure = new RuntimeException('Operation intent unavailable');
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldNotReceive('write');
    $lifecycle = Mockery::mock();
    $lifecycle->shouldReceive('begin')->once()->andThrow($failure);
    $lifecycle->shouldNotReceive('acknowledge');
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: fn (...$arguments) => $lifecycle->begin(...$arguments),
        acknowledgeOperation: fn (string $id) => $lifecycle->acknowledge($id),
    );

    expect(fn () => $operation->run('write', fn (FilesystemOperator $files) => $files->write('report', 'contents'), $filesystem))
        ->toThrow($failure);
    expect(fn () => $operation->assertHealthy())->toThrow(function (RuntimeException $exception) use ($failure): void {
        expect($exception->getPrevious())->toBe($failure);
    });
});

it('rejects calls after closing before any lifecycle or filesystem work', function (string $method) {
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldNotReceive($method);
    $lifecycle = Mockery::mock();
    $lifecycle->shouldNotReceive('begin');
    $lifecycle->shouldNotReceive('acknowledge');
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: fn (...$arguments) => $lifecycle->begin(...$arguments),
        acknowledgeOperation: fn (string $id) => $lifecycle->acknowledge($id),
    );
    $operation->close();

    $callback = fn (FilesystemOperator $files) => match ($method) {
        'read' => $files->read('report'),
        'write' => $files->write('report', 'contents'),
        'readStream' => $files->readStream('report'),
    };

    expect(fn () => $method === 'readStream'
        ? $operation->readStream($callback, $filesystem)
        : $operation->run($method, $callback, $filesystem))
        ->toThrow(RuntimeException::class, 'The snapshot callback has already finished.');
})->with(['read', 'write', 'readStream']);
