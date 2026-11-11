<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadDeadline;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestUploadClock;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('closes the callback filesystem after a callback failure without an uncertain operation', function () {
    $failure = new RuntimeException('callback failed');
    $retained = null;

    expect(function () use (&$retained, $failure): void {
        VersionedStorage::stageDirectory('reports', function (FilesystemAdapter $files) use (&$retained, $failure): never {
            $retained = $files;

            throw $failure;
        });
    })->toThrow($failure);
    expect($retained)->toBeInstanceOf(FilesystemAdapter::class);
    expect(fn () => $retained->put('late', 'stray contents'))
        ->toThrow(RuntimeException::class, 'The snapshot callback has already finished.');
    Storage::disk('local')->assertDirectoryEmpty('reports');
});

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
