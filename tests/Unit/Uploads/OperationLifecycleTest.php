<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Adapters\ScopedFilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\ForkUpload;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadDeadline;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadOperation;

beforeEach(function (): void {
    $this->executor = new FilesystemOperationExecutor(
        Mockery::mock(ForkUpload::class),
        Mockery::mock(FilesystemManager::class),
    );
});

afterEach(function (): void {
    Mockery::close();
});

it('binds each acknowledgement to its own successfully begun operation', function () {
    $events = [];
    $begun = [];
    $acknowledged = [];
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldReceive('write')->once()->with('first', 'first');
    $filesystem->shouldReceive('write')->once()->with('second', 'second');
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: function (string $id, string $name, UploadDeadline $deadline) use (&$events, &$begun, &$acknowledged): Closure {
            expect($name)->toBe('write');
            $events[] = 'begin';
            $begun[] = $id;

            return function () use ($id, &$events, &$acknowledged): void {
                $events[] = 'acknowledge';
                $acknowledged[] = $id;
            };
        },
        executor: $this->executor,
        clock: new UploadClock,
    );

    foreach (['first', 'second'] as $path) {
        $operation->run('write', function (FilesystemOperator $files) use ($path, &$events): void {
            $events[] = "write {$path}";
            $files->write($path, $path);
        }, $filesystem);
    }

    expect($events)->toBe(['begin', 'write first', 'acknowledge', 'begin', 'write second', 'acknowledge'])
        ->and(array_unique($begun))->toHaveCount(2)
        ->and($acknowledged)->toBe($begun);
});

it('closes an unacknowledged stream and rejects subsequent execution with the original cause', function () {
    $stream = fopen('php://temp', 'w+b');
    $failure = new RuntimeException('Read acknowledgement failed');
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldReceive('readStream')->once()->with('report')->andReturn($stream);
    $filesystem->shouldNotReceive('read');
    $lifecycle = Mockery::mock();
    $lifecycle->shouldReceive('begin')->once()->andReturn(function () use ($lifecycle): void {
        $lifecycle->acknowledge('');
    });
    $lifecycle->shouldReceive('acknowledge')->once()->with('')->andThrow($failure);
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: fn (...$arguments) => $lifecycle->begin(...$arguments),
        executor: $this->executor,
        clock: new UploadClock,
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
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'contents');
    rewind($stream);
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldReceive('readStream')->once()->with('report')->andReturn($stream);
    $lifecycle = Mockery::mock();
    $lifecycle->shouldReceive('begin')->once()->andReturn(function () use ($lifecycle): void {
        $lifecycle->acknowledge('');
    });
    $lifecycle->shouldReceive('acknowledge')->once()->with('');
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: fn (...$arguments) => $lifecycle->begin(...$arguments),
        executor: $this->executor,
        clock: new UploadClock,
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
    $lifecycle->shouldReceive('begin')->once()->andReturn(function () use ($lifecycle): void {
        $lifecycle->acknowledge('');
    });
    $lifecycle->shouldNotReceive('acknowledge');
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: fn (...$arguments) => $lifecycle->begin(...$arguments),
        executor: $this->executor,
        clock: new UploadClock,
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
        executor: $this->executor,
        clock: new UploadClock,
    );

    expect(fn () => $operation->run('write', fn (FilesystemOperator $files) => $files->write('report', 'contents'), $filesystem))
        ->toThrow($failure);
    expect(fn () => $operation->assertHealthy())->toThrow(function (RuntimeException $exception) use ($failure): void {
        expect($exception->getPrevious())->toBe($failure);
    });
});

it('consumes a supervised directory listing before acknowledging the operation', function () {
    $visited = false;
    $contents = (function () use (&$visited): Generator {
        $visited = true;
        yield new FileAttributes('reports/version/one', 8);
    })();
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldReceive('listContents')->once()->andReturn(new DirectoryListing($contents));
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: function () use (&$visited): Closure {
            return function () use (&$visited): void {
                expect($visited)->toBeTrue();
            };
        },
        executor: $this->executor,
        clock: new UploadClock,
    );
    $scoped = new ScopedFilesystemOperator($filesystem, 'reports/version', $operation);

    $listing = $scoped->listContents('');
    expect($visited)->toBeTrue()
        ->and($listing->toArray()[0]->path())->toBe('one');
});
