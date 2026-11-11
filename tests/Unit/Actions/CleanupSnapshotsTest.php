<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Date;
use SkyRaptor\LaravelVersionedStorage\Actions\CleanupSnapshots;
use SkyRaptor\LaravelVersionedStorage\Actions\RecoverUploads;
use SkyRaptor\LaravelVersionedStorage\Actions\RegisterResource;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

beforeEach(function (): void {
    Date::setTestNow('2026-04-01 12:00:00');
    $this->connection = Mockery::mock(Connection::class);
    $this->resource = new ResourceIdentity('reports', 'local', true);
    $this->metadata = new ResourceMetadata($this->connection, $this->resource);
    $this->filesystem = Mockery::mock(FilesystemAdapter::class);
    $ownership = new ResourceOwnership(
        $this->connection, $this->resource, $this->filesystem,
        Mockery::mock(FilesystemManager::class), Mockery::mock(Repository::class),
    );
    $recovery = new RecoverUploads(
        $this->connection, $this->resource, $this->metadata,
        new RegisterResource($this->connection, $this->resource, $this->metadata, $ownership),
    );
    $this->cleanup = new CleanupSnapshots(
        $this->connection, $this->metadata, $recovery,
        new SnapshotDeletion($this->connection, $this->resource, $this->metadata, $ownership, $this->filesystem),
    );
});

afterEach(function (): void {
    Date::setTestNow();
    Mockery::close();
});

it('rejects cleanup inside a caller Laravel transaction without touching metadata or files', function () {
    $this->connection->shouldReceive('transactionLevel')->once()->andReturn(1);
    $this->connection->shouldNotReceive('getRawPdo', 'table', 'rollBack');

    expect(fn () => $this->cleanup->cleanup())
        ->toThrow(RuntimeException::class, 'Version cleanup cannot run inside an active metadata transaction.');
});

it('rejects cleanup inside a raw PDO transaction without taking ownership of it', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->once()->andReturn(true);
    $pdo->shouldNotReceive('rollBack');
    $this->connection->shouldReceive('transactionLevel')->once()->andReturn(0);
    $this->connection->shouldReceive('getRawPdo')->once()->andReturn($pdo);
    $this->connection->shouldNotReceive('table', 'rollBack');

    expect(fn () => $this->cleanup->cleanup())
        ->toThrow(RuntimeException::class, 'Version cleanup cannot run inside an active metadata transaction.');
});

it('rejects discard inside a caller Laravel transaction before committing intent', function () {
    $this->connection->shouldReceive('transactionLevel')->once()->andReturn(1);
    $this->connection->shouldNotReceive('getRawPdo', 'table', 'rollBack');

    expect(fn () => $this->cleanup->discard('unpublished'))
        ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction');
});

it('rejects discard inside a raw PDO transaction before committing intent', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->once()->andReturn(true);
    $pdo->shouldNotReceive('rollBack');
    $this->connection->shouldReceive('transactionLevel')->once()->andReturn(0);
    $this->connection->shouldReceive('getRawPdo')->once()->andReturn($pdo);
    $this->connection->shouldNotReceive('table', 'rollBack');

    expect(fn () => $this->cleanup->discard('unpublished'))
        ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction');
});

function discardStageQueries(Connection $connection, ResourceIdentity $resource, Builder $stages): void
{
    $resources = Mockery::mock(Builder::class);
    $connection->shouldReceive('table')->with('versioned_storage_stages')->andReturn($stages);
    $connection->shouldReceive('table')->with('versioned_storage_resources')->andReturn($resources);
    $connection->shouldReceive('transactionLevel')->andReturn(0);
    $connection->shouldReceive('getRawPdo')->andReturn(null);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $resources->shouldReceive('select')->with('id')->andReturnSelf();
    $resources->shouldReceive('where')->with('key', $resource->key)->andReturnSelf();
    $stages->shouldReceive('useWritePdo')->andReturnSelf();
    $stages->shouldReceive('where')->with('version', 'unpublished')->andReturnSelf();
    $stages->shouldReceive('whereIn')->with('resource_id', $resources)->andReturnSelf();
    $stages->shouldReceive('lock')->with(true)->andReturnSelf();
}

it('rejects discarding a published stage before changing its state or deleting files', function () {
    $stages = Mockery::mock(Builder::class);
    discardStageQueries($this->connection, $this->resource, $stages);
    $this->connection->shouldReceive('transaction')->once()->with(Mockery::type(Closure::class), 1)
        ->andReturnUsing(static fn (Closure $work): mixed => $work());
    $stages->shouldReceive('first')->once()->andReturn((object) ['state' => 'published']);
    $stages->shouldNotReceive('update', 'delete');

    expect(fn () => $this->cleanup->discard('unpublished'))
        ->toThrow(RuntimeException::class, 'Published stage [unpublished] cannot be discarded.');
});

it('rejects discarding an uploading stage whose recovery deadline is still in the future', function () {
    $stages = Mockery::mock(Builder::class);
    discardStageQueries($this->connection, $this->resource, $stages);
    $this->connection->shouldReceive('transaction')->once()->with(Mockery::type(Closure::class), 1)
        ->andReturnUsing(static fn (Closure $work): mixed => $work());
    $stages->shouldReceive('first')->once()->andReturn((object) [
        'state' => 'uploading', 'cleanup_after' => '2026-04-01 12:00:01',
    ]);
    $stages->shouldNotReceive('update', 'delete');

    expect(fn () => $this->cleanup->discard('unpublished'))
        ->toThrow(RuntimeException::class, 'must retain its journal until the cleanup deadline');
});

it('treats a missing stage as an idempotent discard without physical deletion', function () {
    $stages = Mockery::mock(Builder::class);
    discardStageQueries($this->connection, $this->resource, $stages);
    $this->connection->shouldReceive('transaction')->twice()->with(Mockery::type(Closure::class), 1)
        ->andReturnUsing(static fn (Closure $work): mixed => $work());
    $stages->shouldReceive('first')->twice()->andReturnNull();
    $stages->shouldNotReceive('update', 'delete');

    expect($this->cleanup->discard('unpublished'))->toBeNull();
});

it('commits discard intent before rechecking eligibility in a separate transaction', function (string $state, string $deadline) {
    $stages = Mockery::mock(Builder::class);
    discardStageQueries($this->connection, $this->resource, $stages);
    $this->connection->shouldReceive('transaction')->twice()->with(Mockery::type(Closure::class), 1)
        ->andReturnUsing(static fn (Closure $work): mixed => $work());
    $stages->shouldReceive('first')->twice()->andReturn(
        (object) ['state' => $state, 'cleanup_after' => $deadline],
        (object) ['state' => 'published'],
    );
    $stages->shouldReceive('update')->once()->with(Mockery::on(
        static fn (array $changes): bool => $changes['state'] === 'discarding'
            && $changes['cleanup_after']->toDateTimeString() === '2026-04-01 12:00:00',
    ));
    $stages->shouldNotReceive('delete');

    expect(fn () => $this->cleanup->discard('unpublished'))
        ->toThrow(RuntimeException::class, 'Stage [unpublished] is not available for deletion.');
})->with([
    'ready stages do not need to wait for an old upload deadline' => ['ready', '2026-04-01 12:00:01'],
    'interrupted discard is retryable' => ['discarding', '2026-04-01 12:00:01'],
    'upload reaches its exact deadline' => ['uploading', '2026-04-01 12:00:00'],
    'upload has passed its deadline' => ['uploading', '2026-04-01 11:59:59'],
]);
