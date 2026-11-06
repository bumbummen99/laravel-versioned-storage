<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Connection;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;

it('does not roll back an unchanged transaction depth after a connection failure', function (int $depth) {
    $failure = new RuntimeException('connection failure');
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->andReturn($depth);
    $connection->shouldReceive('transaction')->once()->andThrow($failure);
    $connection->shouldNotReceive('rollBack');

    if ($depth === 0) {
        $connection->shouldReceive('getRawPdo')->andReturn(null);
    }
    $handler = new VersionedStorageHandler('report', 'local', false, $connection);

    expect(fn () => $handler->get(''))->toThrow($failure);
})->with(['no transaction' => 0, 'existing outer transaction' => 1]);

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
    $handler = new VersionedStorageHandler('report', 'local', false, $connection);

    expect(fn () => $handler->get(''))->toThrow($recoveryFailure);
});

it('rolls back a failed commit even when Laravel reports transaction depth zero', function () {
    $failure = new RuntimeException('commit failed');
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->andReturn(0);
    $connection->shouldReceive('transaction')->once()->andThrow($failure);
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->once()->andReturn(true);
    $pdo->shouldReceive('rollBack')->once();
    $connection->shouldReceive('getRawPdo')->once()->andReturn($pdo);
    $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
    $handler = new VersionedStorageHandler('report', 'local', false, $connection);

    expect(fn () => $handler->get(''))->toThrow($failure);
});
