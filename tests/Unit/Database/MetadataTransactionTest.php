<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;

afterEach(function (): void {
    Mockery::close();
});

it('returns the callback result without inspecting PDO on success', function () {
    $connection = Mockery::mock(Connection::class);
    $callback = static fn (): object => new stdClass;
    $result = new stdClass;
    $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
    $connection->shouldReceive('transaction')->once()->with($callback, 1)->andReturn($result);
    $connection->shouldNotReceive('getRawPdo', 'rollBack');

    expect(MetadataTransaction::run($connection, $callback))->toBe($result);
});

it('passes the requested retry budget and callback to Laravel', function () {
    $connection = Mockery::mock(Connection::class);
    $callback = static fn (): string => 'completed';
    $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
    $connection->shouldReceive('transaction')->once()->with($callback, 5)->andReturnUsing(
        static fn (Closure $work): mixed => $work(),
    );

    expect(MetadataTransaction::run($connection, $callback, 5))->toBe('completed');
});

it('does not roll back caller work when a failure leaves the entry depth unchanged', function (int $depth) {
    $failure = new LogicException('transaction failed');
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->andReturn($depth);
    $connection->shouldReceive('transaction')->once()->andThrow($failure);
    $connection->shouldNotReceive('rollBack', 'getPdo');

    if ($depth === 0) {
        $connection->shouldReceive('getRawPdo')->once()->andReturn(null);
    } else {
        $connection->shouldNotReceive('getRawPdo');
    }

    expect(fn () => MetadataTransaction::run($connection, static fn () => null))->toThrow($failure);
})->with(['no caller transaction' => 0, 'caller owns outer transaction' => 1]);

it('restores exactly the entry depth when beginning a transaction leaves an extra level', function (int $depth) {
    $failure = new RuntimeException('beginning listener failed');
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->andReturn($depth, $depth + 1, $depth);
    $connection->shouldReceive('transaction')->once()->andThrow($failure);
    $connection->shouldReceive('rollBack')->once()->with($depth);

    if ($depth === 0) {
        $connection->shouldReceive('getRawPdo')->once()->andReturn(null);
    } else {
        $connection->shouldNotReceive('getRawPdo');
    }

    expect(fn () => MetadataTransaction::run($connection, static fn () => null))->toThrow($failure);
})->with(['new transaction' => 0, 'nested savepoint' => 1]);

it('does not attempt to recreate a caller transaction already lost by Laravel', function () {
    $failure = new RuntimeException('connection lost');
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->andReturn(1, 0, 0);
    $connection->shouldReceive('transaction')->once()->andThrow($failure);
    $connection->shouldReceive('getRawPdo')->once()->andReturn(null);
    $connection->shouldNotReceive('rollBack', 'beginTransaction');

    expect(fn () => MetadataTransaction::run($connection, static fn () => null))->toThrow($failure);
});

it('rolls back a PDO transaction left behind at Laravel depth zero', function () {
    $failure = new RuntimeException('commit failed');
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->once()->andReturn(true);
    $pdo->shouldReceive('rollBack')->once();
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->andReturn(0);
    $connection->shouldReceive('transaction')->once()->andThrow($failure);
    $connection->shouldReceive('getRawPdo')->once()->andReturn($pdo);
    $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
    $connection->shouldNotReceive('rollBack');

    expect(fn () => MetadataTransaction::run($connection, static fn () => null))->toThrow($failure);
});

it('checks PDO again after restoring Laravel depth', function () {
    $failure = new RuntimeException('beginning failed');
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->once()->andReturn(true);
    $pdo->shouldReceive('rollBack')->once();
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->andReturn(0, 1, 0);
    $connection->shouldReceive('transaction')->once()->andThrow($failure);
    $connection->shouldReceive('rollBack')->once()->with(0)->globally()->ordered();
    $connection->shouldReceive('getRawPdo')->once()->globally()->ordered()->andReturn($pdo);
    $connection->shouldReceive('getPdo')->once()->andReturn($pdo);

    expect(fn () => MetadataTransaction::run($connection, static fn () => null))->toThrow($failure);
});

it('does not issue a PDO rollback after the database already ended the transaction', function () {
    $failure = new RuntimeException('committed listener failed');
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->once()->andReturn(false);
    $pdo->shouldNotReceive('rollBack');
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->andReturn(0);
    $connection->shouldReceive('transaction')->once()->andThrow($failure);
    $connection->shouldReceive('getRawPdo')->once()->andReturn($pdo);
    $connection->shouldNotReceive('getPdo');

    expect(fn () => MetadataTransaction::run($connection, static fn () => null))->toThrow($failure);
});

it('detects an active PDO transaction without resolving a lazy connection', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->once()->andReturn(true);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getRawPdo')->once()->andReturn($pdo);
    $connection->shouldNotReceive('getPdo');

    expect(MetadataTransaction::hasPdoTransaction($connection))->toBeTrue();
});

it('recognizes an idle PDO connection', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->once()->andReturn(false);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getRawPdo')->once()->andReturn($pdo);

    expect(MetadataTransaction::hasPdoTransaction($connection))->toBeFalse();
});

it('does not connect when checking a disconnected PDO', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getRawPdo')->once()->andReturn(null);
    $connection->shouldNotReceive('getPdo');

    expect(MetadataTransaction::hasPdoTransaction($connection))->toBeFalse();
});

it('does not invoke a lazy PDO resolver when checking transaction state', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getRawPdo')->once()->andReturn(static fn () => throw new LogicException('must not connect'));
    $connection->shouldNotReceive('getPdo');

    expect(MetadataTransaction::hasPdoTransaction($connection))->toBeFalse();
});

it('rejects a Laravel transaction without consulting PDO', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
    $connection->shouldNotReceive('getRawPdo', 'rollBack', 'transaction');

    expect(fn () => MetadataTransaction::assertOutsideTransaction($connection))
        ->toThrow(RuntimeException::class, 'Stage first, then publish inside the transaction');
});

it('rejects an active raw PDO transaction without rolling it back', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->once()->andReturn(true);
    $pdo->shouldNotReceive('rollBack');
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
    $connection->shouldReceive('getRawPdo')->once()->andReturn($pdo);
    $connection->shouldNotReceive('rollBack', 'transaction');

    expect(fn () => MetadataTransaction::assertOutsideTransaction($connection))
        ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction');
});

it('allows work outside a transaction on an idle PDO connection', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->once()->andReturn(false);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
    $connection->shouldReceive('getRawPdo')->once()->andReturn($pdo);

    expect(MetadataTransaction::assertOutsideTransaction($connection))->toBeNull();
});

it('allows work without opening a disconnected connection', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
    $connection->shouldReceive('getRawPdo')->once()->andReturn(null);
    $connection->shouldNotReceive('getPdo');

    expect(MetadataTransaction::assertOutsideTransaction($connection))->toBeNull();
});

it('allows work without invoking a lazy PDO resolver', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
    $connection->shouldReceive('getRawPdo')->once()->andReturn(static fn () => throw new LogicException('must not connect'));
    $connection->shouldNotReceive('getPdo');

    expect(MetadataTransaction::assertOutsideTransaction($connection))->toBeNull();
});
