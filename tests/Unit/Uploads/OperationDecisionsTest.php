<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Carbon;
use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\ForkUpload;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadDeadline;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadOperation;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestUploadClock;

beforeEach(function (): void {
    $this->clock = new TestUploadClock(Carbon::parse('2026-01-01 00:00:00'));
    $this->filesystem = Mockery::mock(FilesystemOperator::class);
    $this->executor = new FilesystemOperationExecutor(
        Mockery::mock(ForkUpload::class),
        Mockery::mock(FilesystemManager::class),
    );
});

afterEach(function (): void {
    Mockery::close();
});

it('records the actual mutation name before executing it', function (string $name) {
    $begun = false;
    $acknowledged = false;
    $operation = new UploadOperation(
        ['timeout' => 10, 'fork' => false], [],
        function (string $id, string $mutation, UploadDeadline $deadline) use ($name, &$begun, &$acknowledged): Closure {
            expect($id)->toBeUuid()->and($mutation)->toBe($name)->and($deadline->timeout)->toBe(10);
            $begun = true;

            return function () use (&$acknowledged): void {
                $acknowledged = true;
            };
        },
        $this->executor, $this->clock,
    );

    $result = $operation->run($name, function () use (&$begun, &$acknowledged): string {
        expect($begun)->toBeTrue()->and($acknowledged)->toBeFalse();

        return 'completed';
    }, $this->filesystem);

    expect($result)->toBe('completed')->and($acknowledged)->toBeTrue();
})->with([
    'write', 'writeStream', 'createDirectory', 'delete', 'deleteDirectory', 'copy', 'move', 'setVisibility',
]);

it('checks read admission without recording a mutation', function (string $name) {
    $acknowledged = false;
    $operation = new UploadOperation(
        ['timeout' => 10, 'fork' => false], [],
        function (string $id, string $mutation) use (&$acknowledged): Closure {
            expect($id)->toBeUuid()->and($mutation)->toBe('');

            return function () use (&$acknowledged): void {
                $acknowledged = true;
            };
        },
        $this->executor, $this->clock,
    );

    expect($operation->run($name, fn () => 'read result', $this->filesystem))->toBe('read result')
        ->and($acknowledged)->toBeTrue();
})->with([
    'fileExists', 'directoryExists', 'has', 'read', 'listContents', 'lastModified', 'fileSize', 'mimeType', 'visibility',
]);

it('gives each sequential operation a fresh deadline', function () {
    $deadlines = [];
    $operation = new UploadOperation(
        ['timeout' => 10, 'fork' => false], [],
        function (string $id, string $name, UploadDeadline $deadline) use (&$deadlines): Closure {
            $deadlines[] = $deadline;

            return static function (): void {};
        },
        $this->executor, $this->clock,
    );

    $operation->run('read', fn () => 'first', $this->filesystem);
    $this->clock->advanceMonotonicSeconds(11);
    $this->clock->time->addSeconds(11);
    expect($operation->run('read', fn () => 'second', $this->filesystem))->toBe('second');

    expect($deadlines)->toHaveCount(2)
        ->and($deadlines[0])->not->toBe($deadlines[1])
        ->and($deadlines[0]->recoveryAfter(0)->toTimeString())->toBe('00:00:10')
        ->and($deadlines[1]->recoveryAfter(0)->toTimeString())->toBe('00:00:21');
});

it('passes captured disk configuration and execution policy to the executor', function () {
    $configuration = ['driver' => 'sftp', 'root' => '/reports'];
    $callback = fn () => 'contents';
    $executor = Mockery::mock(FilesystemOperationExecutor::class);
    $executor->shouldReceive('execute')->once()->with(
        $callback, $this->filesystem, $configuration,
        Mockery::on(fn (UploadDeadline $deadline): bool => $deadline->timeout === 23), true,
    )->andReturn('contents');
    $operation = new UploadOperation(
        ['timeout' => 23, 'fork' => true], $configuration,
        fn () => static function (): void {},
        $executor, $this->clock,
    );

    expect($operation->run('read', $callback, $this->filesystem))->toBe('contents');
});

it('does not acknowledge executor failure and retains its exact cause after closing', function () {
    $failure = new LogicException('storage unavailable');
    $acknowledged = false;
    $operation = new UploadOperation(
        ['timeout' => 10, 'fork' => false], [],
        function () use (&$acknowledged): Closure {
            return function () use (&$acknowledged): void {
                $acknowledged = true;
            };
        },
        $this->executor, $this->clock,
    );

    expect(fn () => $operation->run('write', fn () => throw $failure, $this->filesystem))->toThrow($failure);
    $operation->close();
    expect(fn () => $operation->assertHealthy())->toThrow(function (RuntimeException $exception) use ($failure): void {
        expect($exception->getPrevious())->toBe($failure);
    });
    expect($acknowledged)->toBeFalse();
});

it('makes ordinary acknowledgement failures sticky too', function () {
    $failure = new LogicException('acknowledgement lost');
    $operation = new UploadOperation(
        ['timeout' => 10, 'fork' => false], [],
        fn () => fn () => throw $failure,
        $this->executor, $this->clock,
    );

    expect(fn () => $operation->run('write', fn () => null, $this->filesystem))->toThrow($failure);
    expect(fn () => $operation->assertHealthy())->toThrow(function (RuntimeException $exception) use ($failure): void {
        expect($exception->getPrevious())->toBe($failure);
    });
});

it('allows repeated close calls without reopening the callback', function () {
    $operation = new UploadOperation(
        ['timeout' => 10, 'fork' => false], [],
        fn () => throw new LogicException('must not begin'),
        $this->executor, $this->clock,
    );

    $operation->assertHealthy();
    $operation->close();
    $operation->close();

    expect(fn () => $operation->assertHealthy())->toThrow(RuntimeException::class, 'callback has already finished');
});
