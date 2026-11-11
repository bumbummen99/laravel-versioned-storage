<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Carbon;
use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\ForkUpload;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadDeadline;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestUploadClock;

afterEach(function (): void {
    Mockery::close();
});

it('propagates supervisor failure without falling back to inline execution', function () {
    $failure = new LogicException('child interrupted');
    $fork = Mockery::mock(ForkUpload::class);
    $fork->shouldReceive('run')->once()->with(Mockery::type(Closure::class), 17)->andThrow($failure);
    $manager = Mockery::mock(FilesystemManager::class);
    $manager->shouldNotReceive('build');
    $executor = new FilesystemOperationExecutor($fork, $manager);

    expect(fn () => $executor->execute(
        fn () => throw new LogicException('must not fall back'),
        Mockery::mock(FilesystemOperator::class), [],
        new UploadDeadline(17, true, new TestUploadClock(Carbon::parse('2026-01-01'))), true,
    ))->toThrow($failure);
});

it('rejects a deadline consumed during supervisor startup before rebuilding the disk', function () {
    $clock = new TestUploadClock(Carbon::parse('2026-01-01'));
    $deadline = new UploadDeadline(17, true, $clock);
    $fork = Mockery::mock(ForkUpload::class);
    $fork->shouldReceive('run')->once()->andReturnUsing(function (Closure $callback) use ($clock): mixed {
        $clock->advanceMonotonicSeconds(17);

        return $callback();
    });
    $manager = Mockery::mock(FilesystemManager::class);
    $manager->shouldNotReceive('build');
    $executor = new FilesystemOperationExecutor($fork, $manager);

    expect(fn () => $executor->execute(
        fn () => throw new LogicException('expired work must not start'),
        Mockery::mock(FilesystemOperator::class), [], $deadline, true,
    ))->toThrow(RuntimeException::class, 'timed out before it could start');
});

it('uses the rebuilt driver and preserves a false child result', function () {
    $configuration = ['driver' => 'sftp', 'root' => '/reports'];
    $original = Mockery::mock(FilesystemOperator::class);
    $original->shouldNotReceive('fileExists');
    $rebuilt = Mockery::mock(FilesystemOperator::class);
    $rebuilt->shouldReceive('fileExists')->once()->with('missing')->andReturnFalse();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('getDriver')->once()->andReturn($rebuilt);
    $manager = Mockery::mock(FilesystemManager::class);
    $manager->shouldReceive('build')->once()->with($configuration)->andReturn($disk);
    $fork = Mockery::mock(ForkUpload::class);
    $fork->shouldReceive('run')->once()->andReturnUsing(fn (Closure $callback) => $callback());
    $executor = new FilesystemOperationExecutor($fork, $manager);

    try {
        expect($executor->execute(
            fn (FilesystemOperator $files): bool => $files->fileExists('missing'),
            $original, $configuration,
            new UploadDeadline(17, true, new TestUploadClock(Carbon::parse('2026-01-01'))), true,
        ))->toBeFalse();
    } finally {
        pcntl_alarm(0);
    }
});

it('propagates disk reconstruction failure instead of using the original driver', function () {
    $failure = new LogicException('invalid disk credentials');
    $configuration = ['driver' => 'sftp'];
    $manager = Mockery::mock(FilesystemManager::class);
    $manager->shouldReceive('build')->once()->with($configuration)->andThrow($failure);
    $fork = Mockery::mock(ForkUpload::class);
    $fork->shouldReceive('run')->once()->andReturnUsing(fn (Closure $callback) => $callback());
    $executor = new FilesystemOperationExecutor($fork, $manager);

    try {
        expect(fn () => $executor->execute(
            fn () => throw new LogicException('must not use the original disk'),
            Mockery::mock(FilesystemOperator::class), $configuration,
            new UploadDeadline(17, true, new TestUploadClock(Carbon::parse('2026-01-01'))), true,
        ))->toThrow($failure);
    } finally {
        pcntl_alarm(0);
    }
});
