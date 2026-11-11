<?php

declare(strict_types=1);

use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadOperation;

beforeEach(function (): void {
    $this->operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: fn () => throw new LogicException('A closed callback must not begin another operation.'),
        executor: Mockery::mock(FilesystemOperationExecutor::class),
        clock: new UploadClock,
    );
    $this->operation->close();
});

afterEach(function (): void {
    Mockery::close();
});

it('rejects reads after the snapshot callback finishes', function () {
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldNotReceive('read');

    expect(fn () => $this->operation->run('read', fn (FilesystemOperator $files) => $files->read('report'), $filesystem))
        ->toThrow(RuntimeException::class, 'The snapshot callback has already finished.');
});

it('rejects writes after the snapshot callback finishes', function () {
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldNotReceive('write');

    expect(fn () => $this->operation->run('write', fn (FilesystemOperator $files) => $files->write('report', 'contents'), $filesystem))
        ->toThrow(RuntimeException::class, 'The snapshot callback has already finished.');
});

it('rejects stream reads after the snapshot callback finishes', function () {
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldNotReceive('readStream');

    expect(fn () => $this->operation->readStream(fn (FilesystemOperator $files) => $files->readStream('report'), $filesystem))
        ->toThrow(RuntimeException::class, 'The snapshot callback has already finished.');
});
