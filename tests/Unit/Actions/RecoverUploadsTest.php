<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use SkyRaptor\LaravelVersionedStorage\Actions\RecoverUploads;
use SkyRaptor\LaravelVersionedStorage\Actions\RegisterResource;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

beforeEach(function (): void {
    Date::setTestNow('2026-04-01 12:00:00');
    $this->connection = Mockery::mock(Connection::class);
    $this->resource = new ResourceIdentity('reports', 'local', true);
    $this->metadata = new ResourceMetadata($this->connection, $this->resource);
    $ownership = new ResourceOwnership(
        $this->connection, $this->resource, Mockery::mock(FilesystemAdapter::class),
        Mockery::mock(FilesystemManager::class), Mockery::mock(Repository::class),
    );
    $this->recovery = new RecoverUploads(
        $this->connection, $this->resource, $this->metadata,
        new RegisterResource($this->connection, $this->resource, $this->metadata, $ownership),
    );
    $this->connection->shouldReceive('transactionLevel')->andReturn(0);
    $this->connection->shouldReceive('getRawPdo')->andReturn(null);
});

afterEach(function (): void {
    Date::setTestNow();
    Mockery::close();
});

function expiredStageDiscovery(Connection $connection, ResourceIdentity $resource, Collection $candidates, ?int $maximum = 23): void
{
    $scan = Mockery::mock(Builder::class);
    $horizon = Mockery::mock(Builder::class);
    $resources = Mockery::mock(Builder::class);
    $connection->shouldReceive('table')->once()->with('versioned_storage_stages')->andReturn($scan);
    $connection->shouldReceive('table')->once()->with('versioned_storage_stages')->andReturn($horizon);
    $connection->shouldReceive('table')->once()->with('versioned_storage_resources')->andReturn($resources);
    $scan->shouldReceive('useWritePdo')->once()->andReturnSelf();
    $resources->shouldReceive('select')->once()->with('id')->andReturnSelf();
    $resources->shouldReceive('where')->once()->with('key', $resource->key)->andReturnSelf();
    $scan->shouldReceive('whereIn')->once()->with('resource_id', $resources)->andReturnSelf();
    $scan->shouldReceive('whereIn')->once()->with('state', ['uploading', 'discarding'])->andReturnSelf();
    $scan->shouldReceive('where')->once()->with('cleanup_after', '<=', Mockery::on(
        static fn (mixed $time): bool => $time->toDateTimeString() === '2026-04-01 12:00:00',
    ))->andReturnSelf();
    $horizon->shouldReceive('useWritePdo')->once()->andReturnSelf();
    $horizon->shouldReceive('max')->once()->with('id')->andReturn($maximum);
    $scan->shouldReceive('where')->once()->with('id', '<=', $maximum ?? 0)->andReturnSelf();
    $scan->shouldReceive('chunkById')->once()->with(100, Mockery::type(Closure::class))
        ->andReturnUsing(static function (int $size, Closure $consume) use ($candidates): void {
            $consume($candidates);
        });
}

function recoveryStageLock(Connection $connection, ResourceIdentity $resource, ?stdClass $stage): void
{
    $locked = Mockery::mock(Builder::class);
    $resources = Mockery::mock(Builder::class);
    $connection->shouldReceive('table')->once()->with('versioned_storage_stages')->andReturn($locked);
    $connection->shouldReceive('table')->once()->with('versioned_storage_resources')->andReturn($resources);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('transaction')->once()->with(Mockery::type(Closure::class), 1)
        ->andReturnUsing(static fn (Closure $work): mixed => $work());
    $locked->shouldReceive('useWritePdo')->once()->andReturnSelf();
    $locked->shouldReceive('where')->once()->with('version', 'abandoned')->andReturnSelf();
    $resources->shouldReceive('select')->once()->with('id')->andReturnSelf();
    $resources->shouldReceive('where')->once()->with('key', $resource->key)->andReturnSelf();
    $locked->shouldReceive('whereIn')->once()->with('resource_id', $resources)->andReturnSelf();
    $locked->shouldReceive('lock')->once()->with('for update skip locked')->andReturnSelf();
    $locked->shouldReceive('first')->once()->andReturn($stage);
}

it('bounds fresh primary discovery to the invocation horizon and excludes ready stages', function (?int $maximum) {
    expiredStageDiscovery($this->connection, $this->resource, new Collection, $maximum);
    $this->connection->shouldNotReceive('transaction');

    $this->recovery->recover();
})->with(['existing maximum' => 23, 'empty stage table' => null]);

it('retains a discovered stage when its locked state no longer permits reclamation', function (?array $stage) {
    expiredStageDiscovery($this->connection, $this->resource, new Collection([(object) ['version' => 'abandoned']]));
    recoveryStageLock($this->connection, $this->resource, $stage === null ? null : (object) $stage);
    $this->connection->shouldNotReceive('table')->with('versioned_storage_operations');
    $this->connection->shouldNotReceive('table')->with('versioned_storage_versions');

    $this->recovery->recover();
})->with([
    'already removed or locked elsewhere' => [null],
    'completed during discovery' => [['state' => 'ready', 'cleanup_after' => '2026-04-01 11:59:59']],
    'published during discovery' => [['state' => 'published', 'cleanup_after' => '2026-04-01 11:59:59']],
    'unrecognized state' => [['state' => 'unknown', 'cleanup_after' => '2026-04-01 11:59:59']],
    'upload extended its deadline' => [['state' => 'uploading', 'cleanup_after' => '2026-04-01 12:00:01']],
    'discard extended its deadline' => [['state' => 'discarding', 'cleanup_after' => '2026-04-01 12:00:01']],
]);

it('retains an expired stage while an individual operation still has recovery time', function (string $state) {
    expiredStageDiscovery($this->connection, $this->resource, new Collection([(object) ['version' => 'abandoned']]));
    recoveryStageLock($this->connection, $this->resource, (object) [
        'state' => $state, 'cleanup_after' => '2026-04-01 12:00:00',
    ]);
    $operations = Mockery::mock(Builder::class);
    $this->connection->shouldReceive('table')->once()->with('versioned_storage_operations')->andReturn($operations);
    $operations->shouldReceive('where')->once()->with('version', 'abandoned')->andReturnSelf();
    $operations->shouldReceive('where')->once()->with('cleanup_after', '>', Mockery::on(
        static fn (mixed $time): bool => $time->toDateTimeString() === '2026-04-01 12:00:00',
    ))->andReturnSelf();
    $operations->shouldReceive('exists')->once()->andReturn(true);
    $this->connection->shouldNotReceive('table')->with('versioned_storage_versions');

    $this->recovery->recover();
})->with(['uploading', 'discarding']);
