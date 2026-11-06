<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestUploadClock;
use SkyRaptor\LaravelVersionedStorage\UploadClock;
use SkyRaptor\LaravelVersionedStorage\UploadDeadline;
use SkyRaptor\LaravelVersionedStorage\UploadOperation;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('can inject an operation failure without mocking disk internals or bypassing journaling', function () {
    $current = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('old', 'original'));
    $executor = Mockery::mock(FilesystemOperationExecutor::class)->makePartial();
    $executor->shouldReceive('execute')->andReturnUsing(function (
        Closure $callback,
        FilesystemOperator $filesystem,
        array $configuration,
        UploadDeadline $deadline,
        bool $fork,
    ): mixed {
        $journal = DB::table('versioned_storage_operations')->first();

        if ($journal?->operation === 'write') {
            expect(DB::transactionLevel())->toBe(0);

            throw new RuntimeException('Injected operation failure');
        }

        return $callback($filesystem);
    });
    app()->instance(FilesystemOperationExecutor::class, $executor);

    expect(fn () => VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('new', 'replacement'), true))
        ->toThrow(RuntimeException::class, 'Injected operation failure');

    expect(VersionedStorage::directory('reports')->versions())->toBe([$current])
        ->and(VersionedStorage::directory('reports')->get('old'))->toBe('original')
        ->and(DB::table('versioned_storage_operations')->sole()->operation)->toBe('write');
    $journal = DB::table('versioned_storage_operations')->sole();
    Storage::disk('local')->assertMissing("reports/{$journal->version}/new");
});

it('does not start filesystem work after recording its journal consumes the operation budget', function () {
    $clock = new TestUploadClock(now());
    app()->instance(UploadClock::class, $clock);
    config()->set('laravel-versioned-storage.upload_timeout', 10);
    Event::listen(TransactionCommitted::class, function () use ($clock): void {
        if (DB::table('versioned_storage_operations')->exists()) {
            $clock->nanoseconds = 10_000_000_000;
        }
    });

    try {
        expect(fn () => VersionedStorage::put('report', 'contents'))
            ->toThrow(RuntimeException::class, 'timed out before it could start');
    } finally {
        Event::forget(TransactionCommitted::class);
    }

    $journal = DB::table('versioned_storage_operations')->sole();
    expect(VersionedStorage::versions('report'))->toBe([]);
    Storage::disk('local')->assertMissing("report/{$journal->version}");
});

it('closes a returned read stream when parent acknowledgement fails', function () {
    $stream = fopen('php://temp', 'w+b');
    $returned = false;
    $executor = Mockery::mock(FilesystemOperationExecutor::class)->makePartial();
    $executor->shouldReceive('readStream')->once()->andReturnUsing(function () use ($stream, &$returned) {
        $returned = true;

        return $stream;
    });

    app()->instance(FilesystemOperationExecutor::class, $executor);
    Event::listen(TransactionBeginning::class, function () use (&$returned): void {
        if ($returned) {
            $returned = false;

            throw new RuntimeException('Read acknowledgement failed');
        }
    });

    try {
        expect(fn () => VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->readStream('one')))
            ->toThrow(RuntimeException::class, 'Read acknowledgement failed');
        expect(is_resource($stream))->toBeFalse();
    } finally {
        Event::forget(TransactionBeginning::class);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }
});

it('rejects further execution after read acknowledgement fails and preserves its cause', function () {
    $filesystem = Storage::disk('local')->getDriver();
    $stream = fopen('php://temp', 'w+b');
    $failure = new RuntimeException('Read acknowledgement failed');
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: function (): void {},
        acknowledgeOperation: fn () => throw $failure,
    );
    $readStream = fn () => $operation->readStream(fn (FilesystemOperator $files) => $stream, $filesystem);
    $executedAgain = false;
    $readCallback = function (FilesystemOperator $files) use (&$executedAgain): string {
        $executedAgain = true;

        return 'unexpected execution';
    };
    $readAgain = fn () => $operation->run('read', $readCallback, $filesystem);

    try {
        expect($readStream)->toThrow($failure);
        expect(is_resource($stream))->toBeFalse();
        expect($readAgain)->toThrow(function (RuntimeException $exception) use ($failure): void {
            expect($exception->getMessage())->toBe('The snapshot failed during a filesystem operation.')
                ->and($exception->getPrevious())->toBe($failure);
        });
        expect($executedAgain)->toBeFalse();
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
});

it('hands an acknowledged read stream to the caller without closing it', function () {
    $filesystem = Storage::disk('local')->getDriver();
    $filesystem->write('report', 'contents');
    $acknowledgments = [];
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: function (): void {},
        acknowledgeOperation: function (string $id) use (&$acknowledgments): void {
            $acknowledgments[] = $id;
        },
    );
    $stream = $operation->readStream(fn (FilesystemOperator $files) => $files->readStream('report'), $filesystem);

    try {
        expect($acknowledgments)->toBe([''])
            ->and(is_resource($stream))->toBeTrue()
            ->and(stream_get_contents($stream))->toBe('contents');
        $operation->assertHealthy();
    } finally {
        fclose($stream);
    }
});

it('does not acknowledge a failed stream execution and marks the operation unhealthy', function () {
    $acknowledged = false;
    $failure = new RuntimeException('Read execution failed');
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: function (): void {},
        acknowledgeOperation: function () use (&$acknowledged): void {
            $acknowledged = true;
        },
    );

    expect(fn () => $operation->readStream(
        fn (FilesystemOperator $files) => throw $failure,
        Storage::disk('local')->getDriver(),
    ))->toThrow($failure);
    expect($acknowledged)->toBeFalse();
    expect(fn () => $operation->assertHealthy())
        ->toThrow(RuntimeException::class, 'The snapshot failed during a filesystem operation.');
});

it('does not execute or acknowledge when beginning an operation fails', function () {
    $failure = new RuntimeException('Operation intent unavailable');
    $executed = false;
    $acknowledged = false;
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: fn () => throw $failure,
        acknowledgeOperation: function () use (&$acknowledged): void {
            $acknowledged = true;
        },
    );
    $writeCallback = function (FilesystemOperator $files) use (&$executed): void {
        $executed = true;
        $files->write('report', 'contents');
    };
    $write = fn () => $operation->run('write', $writeCallback, Storage::disk('local')->getDriver());

    expect($write)->toThrow($failure);
    expect($executed)->toBeFalse()
        ->and($acknowledged)->toBeFalse();
    Storage::disk('local')->assertMissing('report');
    expect(fn () => $operation->assertHealthy())->toThrow(function (RuntimeException $exception) use ($failure): void {
        expect($exception->getPrevious())->toBe($failure);
    });
});

it('rejects calls after closing before any lifecycle or filesystem work', function (string $method) {
    $calls = [];
    $operation = new UploadOperation(
        execution: ['timeout' => 10, 'fork' => false],
        configuration: [],
        beginOperation: function () use (&$calls): void {
            $calls[] = 'begin';
        },
        acknowledgeOperation: function () use (&$calls): void {
            $calls[] = 'acknowledge';
        },
    );
    $filesystem = Storage::disk('local')->getDriver();
    $execute = function (FilesystemOperator $files) use (&$calls): never {
        $calls[] = 'execute';

        throw new RuntimeException('A closed operation must not execute filesystem work.');
    };
    $call = fn () => $method === 'readStream'
        ? $operation->readStream($execute, $filesystem)
        : $operation->run($method, $execute, $filesystem);

    $operation->close();

    expect($call)->toThrow(RuntimeException::class, 'The snapshot callback has already finished.');
    expect($calls)->toBe([]);
})->with(['read', 'write', 'readStream']);
