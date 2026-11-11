<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;

it('reports the original connection failure when rollback recovery also fails', function () {
    $failure = new RuntimeException('connection failure');
    $recoveryFailure = new RuntimeException('rollback failed');
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->andReturn(0, 1);
    $connection->shouldReceive('transaction')->once()->andThrow($failure);
    $connection->shouldReceive('rollBack')->once()->with(0)->andThrow($recoveryFailure);
    $exceptionHandler = Mockery::mock(ExceptionHandler::class);
    $exceptionHandler->shouldReceive('report')->once()->with($failure);
    app()->instance(ExceptionHandler::class, $exceptionHandler);
    expect(fn () => MetadataTransaction::run($connection, static fn () => null))->toThrow($recoveryFailure);
});

it('closes a real SQLite transaction left open by a deferred foreign key commit failure', function () {
    $connection = DB::connection();
    $connection->statement('PRAGMA foreign_keys = ON');

    expect(fn () => MetadataTransaction::run($connection, function () use ($connection): void {
        $connection->statement('PRAGMA defer_foreign_keys = ON');
        $connection->table('versioned_storage_versions')->insert([
            'resource_id' => 999, 'version' => (string) Str::uuid(), 'created_at' => now(),
        ]);
    }))->toThrow(PDOException::class, 'FOREIGN KEY constraint failed');

    expect($connection->transactionLevel())->toBe(0)
        ->and($connection->getPdo()->inTransaction())->toBeFalse()
        ->and($connection->table('versioned_storage_versions')->count())->toBe(0);
});

it('restores a newly opened transaction when a real beginning listener throws before the callback', function () {
    $connection = DB::connection();
    $called = false;
    Event::listen(TransactionBeginning::class, static fn () => throw new RuntimeException('beginning failed'));

    try {
        expect(fn () => MetadataTransaction::run($connection, function () use (&$called): void {
            $called = true;
        }))->toThrow(RuntimeException::class, 'beginning failed');
    } finally {
        Event::forget(TransactionBeginning::class);
    }

    expect($called)->toBeFalse()
        ->and($connection->transactionLevel())->toBe(0)
        ->and($connection->getPdo()->inTransaction())->toBeFalse();
});

it('rolls back only the failed savepoint and leaves caller work available to commit', function () {
    $connection = DB::connection();
    $connection->beginTransaction();
    $connection->table('versioned_storage_reservations')->insert(['version' => 'caller-owned']);
    Event::listen(TransactionBeginning::class, static fn () => throw new RuntimeException('savepoint failed'));

    try {
        expect(fn () => MetadataTransaction::run($connection, static fn () => throw new LogicException('must not execute')))
            ->toThrow(RuntimeException::class, 'savepoint failed');
    } finally {
        Event::forget(TransactionBeginning::class);
    }

    try {
        expect($connection->transactionLevel())->toBe(1)
            ->and($connection->getPdo()->inTransaction())->toBeTrue()
            ->and($connection->table('versioned_storage_reservations')->pluck('version')->all())->toBe(['caller-owned']);
        $connection->commit();
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }
    }

    expect($connection->table('versioned_storage_reservations')->pluck('version')->all())->toBe(['caller-owned']);
});

it('rolls back callback writes when a real committing listener throws', function () {
    $connection = DB::connection();
    Event::listen(TransactionCommitting::class, static fn () => throw new RuntimeException('commit refused'));

    try {
        expect(fn () => MetadataTransaction::run($connection, function () use ($connection): void {
            $connection->table('versioned_storage_reservations')->insert(['version' => 'uncommitted']);
        }))->toThrow(RuntimeException::class, 'commit refused');
    } finally {
        Event::forget(TransactionCommitting::class);
    }

    expect($connection->transactionLevel())->toBe(0)
        ->and($connection->getPdo()->inTransaction())->toBeFalse()
        ->and($connection->table('versioned_storage_reservations')->count())->toBe(0);
});
