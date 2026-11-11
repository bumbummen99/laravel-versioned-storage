<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use SkyRaptor\LaravelVersionedStorage\Actions\PublishStage;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

beforeEach(function (): void {
    $this->connection = Mockery::mock(Connection::class);
    $this->resource = new ResourceIdentity('reports', 'local', false);
    $this->metadata = new ResourceMetadata($this->connection, $this->resource);
    $this->publication = new PublishStage($this->connection, $this->resource, $this->metadata);
    $this->connection->shouldReceive('transactionLevel')->andReturn(0)->byDefault();
    $this->connection->shouldReceive('getRawPdo')->andReturn(null)->byDefault();
    $this->connection->shouldReceive('getDriverName')->andReturn('mysql')->byDefault();
});

afterEach(function (): void {
    Mockery::close();
});

function publicationStageLock(Connection $connection, ResourceIdentity $resource, ?stdClass $stage): void
{
    $stages = Mockery::mock(Builder::class);
    $resources = Mockery::mock(Builder::class);
    $connection->shouldReceive('table')->once()->with('versioned_storage_stages')->andReturn($stages);
    $connection->shouldReceive('table')->once()->with('versioned_storage_resources')->andReturn($resources);
    $stages->shouldReceive('useWritePdo')->once()->andReturnSelf();
    $stages->shouldReceive('where')->once()->with('version', 'replacement')->andReturnSelf();
    $stages->shouldReceive('whereIn')->once()->with('resource_id', $resources)->andReturnSelf();
    $resources->shouldReceive('select')->once()->with('id')->andReturnSelf();
    $resources->shouldReceive('where')->once()->with('key', $resource->key)->andReturnSelf();
    $stages->shouldReceive('lock')->once()->with(true)->andReturnSelf();
    $stages->shouldReceive('first')->once()->andReturn($stage);
    $connection->shouldReceive('transaction')->once()->with(Mockery::type(Closure::class), 5)
        ->andReturnUsing(static fn (Closure $work): mixed => $work());
}

it('rejects publication of a missing or discarded stage before touching visible versions', function () {
    publicationStageLock($this->connection, $this->resource, null);
    $this->connection->shouldNotReceive('table')->with('versioned_storage_versions');

    expect(fn () => $this->publication->publish('replacement'))
        ->toThrow(RuntimeException::class, 'Stage [replacement] does not exist or was discarded.');
});

it('rejects publication until the locked stage is ready', function (string $state) {
    publicationStageLock($this->connection, $this->resource, (object) ['state' => $state]);
    $this->connection->shouldNotReceive('table')->with('versioned_storage_versions');

    expect(fn () => $this->publication->publish('replacement', cleanup: true))
        ->toThrow(RuntimeException::class, 'Stage [replacement] is not ready for publication.');
})->with(['uploading', 'discarding', 'unknown-state']);

it('does not repoint or retire anything when retrying an already published stage', function (bool $cleanup) {
    publicationStageLock($this->connection, $this->resource, (object) ['state' => 'published']);
    $this->connection->shouldNotReceive('table')->with('versioned_storage_versions');

    expect($this->publication->publish('replacement', $cleanup))->toBeNull();
})->with(['ordinary retry' => false, 'retry requests retirement' => true]);

it('rejects raw PDO publication before opening a Laravel transaction', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->once()->andReturn(true);
    $pdo->shouldNotReceive('rollBack');
    $this->connection->shouldReceive('getRawPdo')->once()->andReturn($pdo);
    $this->connection->shouldNotReceive('transaction', 'table');

    expect(fn () => $this->publication->publish('replacement'))
        ->toThrow(RuntimeException::class, 'not raw PDO transactions');
});

it('allows a managed outer transaction without treating its PDO ownership as raw', function () {
    $this->connection->shouldReceive('transactionLevel')->twice()->andReturn(1);
    $this->connection->shouldNotReceive('getRawPdo');
    publicationStageLock($this->connection, $this->resource, (object) ['state' => 'published']);

    expect($this->publication->publish('replacement'))->toBeNull();
});
