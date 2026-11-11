<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

beforeEach(function (): void {
    $this->connection = Mockery::mock(Connection::class);
    $this->connection->shouldReceive('getTablePrefix')->andReturn('');
    $this->connection->shouldReceive('getDatabaseName')->andReturn('metadata');
    $this->resource = new ResourceIdentity('reports', 'local', true);
    $this->metadata = new ResourceMetadata($this->connection, $this->resource);
});

afterEach(function (): void {
    Mockery::close();
});

it('checks resource existence on the primary connection by its complete identity', function (bool $exists) {
    $query = Mockery::mock(Builder::class);
    $this->connection->shouldReceive('table')->once()->with('versioned_storage_resources')->andReturn($query);
    $query->shouldReceive('useWritePdo')->once()->andReturnSelf();
    $query->shouldReceive('where')->once()->with('key', $this->resource->key)->andReturnSelf();
    $query->shouldReceive('exists')->once()->andReturn($exists);

    expect($this->metadata->exists())->toBe($exists);
})->with(['registered' => true, 'unregistered' => false]);

it('scopes version queries to the supplied locked resource and primary PDO', function () {
    $query = new Builder($this->connection, new Grammar($this->connection), new Processor);
    $query->from('versioned_storage_versions');
    $this->connection->shouldReceive('table')->once()->with('versioned_storage_versions')->andReturn($query);

    $versions = $this->metadata->resourceVersions((object) ['id' => 42]);

    expect($versions->useWritePdo)->toBeTrue()
        ->and($versions->toSql())->toBe('select * from "versioned_storage_versions" where "resource_id" = ?')
        ->and($versions->getBindings())->toBe([42]);
});

it('scopes version discovery through a resource identity subquery on the primary PDO', function () {
    $versions = (new Builder($this->connection, new Grammar($this->connection), new Processor))->from('versioned_storage_versions');
    $resources = (new Builder($this->connection, new Grammar($this->connection), new Processor))->from('versioned_storage_resources');
    $this->connection->shouldReceive('table')->once()->with('versioned_storage_versions')->andReturn($versions);
    $this->connection->shouldReceive('table')->once()->with('versioned_storage_resources')->andReturn($resources);

    $query = $this->metadata->versions();

    expect($query->useWritePdo)->toBeTrue()
        ->and($query->toSql())->toBe('select * from "versioned_storage_versions" where "resource_id" in (select "id" from "versioned_storage_resources" where "key" = ?)')
        ->and($query->getBindings())->toBe([$this->resource->key]);
});

it('scopes a stage by both version and resource identity on the primary PDO', function () {
    $stages = (new Builder($this->connection, new Grammar($this->connection), new Processor))->from('versioned_storage_stages');
    $resources = (new Builder($this->connection, new Grammar($this->connection), new Processor))->from('versioned_storage_resources');
    $this->connection->shouldReceive('table')->once()->with('versioned_storage_stages')->andReturn($stages);
    $this->connection->shouldReceive('table')->once()->with('versioned_storage_resources')->andReturn($resources);

    $query = $this->metadata->stage('revision');

    expect($query->useWritePdo)->toBeTrue()
        ->and($query->toSql())->toBe('select * from "versioned_storage_stages" where "version" = ? and "resource_id" in (select "id" from "versioned_storage_resources" where "key" = ?)')
        ->and($query->getBindings())->toBe(['revision', $this->resource->key]);
});

it('builds a fresh version query on each invocation', function () {
    $first = (new Builder($this->connection, new Grammar($this->connection), new Processor))->from('versioned_storage_versions');
    $second = clone $first;
    $this->connection->shouldReceive('table')->twice()->with('versioned_storage_versions')->andReturn($first, $second);

    $retired = $this->metadata->resourceVersions((object) ['id' => 42])->where('pending_delete', true);
    $all = $this->metadata->resourceVersions((object) ['id' => 42]);

    expect($all)->not->toBe($retired)
        ->and($all->getBindings())->toBe([42])
        ->and($retired->getBindings())->toBe([42, true]);
});

it('locks a resource without opening or closing the caller transaction', function (string $driver) {
    $record = (object) ['id' => 42];
    $query = Mockery::mock(Builder::class);
    $this->connection->shouldReceive('table')->once()->with('versioned_storage_resources')->andReturn($query);
    $this->connection->shouldReceive('getDriverName')->andReturn($driver);
    $this->connection->shouldNotReceive('transaction', 'beginTransaction', 'commit', 'rollBack');
    $query->shouldReceive('where')->once()->with('key', $this->resource->key)->andReturnSelf();

    if ($driver === 'sqlite') {
        $query->shouldReceive('update')->once()->with(['key' => $this->resource->key])->globally()->ordered();
    } else {
        $query->shouldNotReceive('update');
    }
    $query->shouldReceive('lockForUpdate')->once()->globally()->ordered()->andReturnSelf();
    $query->shouldReceive('first')->once()->andReturn($record);

    expect($this->metadata->lockResource())->toBe($record);
})->with(['sqlite', 'mysql', 'pgsql', 'sqlsrv']);

it('returns no resource when the locked row disappeared', function () {
    $query = Mockery::mock(Builder::class);
    $this->connection->shouldReceive('table')->once()->with('versioned_storage_resources')->andReturn($query);
    $this->connection->shouldReceive('getDriverName')->andReturn('mysql');
    $query->shouldReceive('where')->with('key', $this->resource->key)->andReturnSelf();
    $query->shouldReceive('lockForUpdate')->once()->andReturnSelf();
    $query->shouldReceive('first')->once()->andReturnNull();

    expect($this->metadata->lockResource())->toBeNull();
});

it('selects the supported stage lock without taking ownership of the transaction', function (string $driver, bool $skipLocked, string|bool $lock) {
    $record = (object) ['state' => 'uploading'];
    $stage = Mockery::mock(Builder::class);
    $resources = Mockery::mock(Builder::class);
    $this->connection->shouldReceive('table')->once()->with('versioned_storage_stages')->andReturn($stage);
    $this->connection->shouldReceive('table')->once()->with('versioned_storage_resources')->andReturn($resources);
    $this->connection->shouldReceive('getDriverName')->andReturn($driver);
    $this->connection->shouldNotReceive('transaction', 'beginTransaction', 'commit', 'rollBack');
    $resources->shouldReceive('select')->once()->with('id')->andReturnSelf();
    $resources->shouldReceive('where')->once()->with('key', $this->resource->key)->andReturnSelf();
    $stage->shouldReceive('useWritePdo')->once()->andReturnSelf();
    $stage->shouldReceive('where')->once()->with('version', 'revision')->andReturnSelf();
    $stage->shouldReceive('whereIn')->once()->with('resource_id', $resources)->andReturnSelf();

    if ($driver === 'sqlite') {
        $stage->shouldReceive('update')->once()->with(['version' => 'revision'])->globally()->ordered();
    } else {
        $stage->shouldNotReceive('update');
    }
    $stage->shouldReceive('lock')->once()->with($lock)->globally()->ordered()->andReturnSelf();
    $stage->shouldReceive('first')->once()->andReturn($record);

    expect($this->metadata->lockStage('revision', $skipLocked))->toBe($record);
})->with([
    'SQLite ordinary lock' => ['sqlite', false, true],
    'SQLite write promotion even when skipping' => ['sqlite', true, true],
    'MySQL ordinary lock' => ['mysql', false, true],
    'MySQL skip locked' => ['mysql', true, 'for update skip locked'],
    'PostgreSQL ordinary lock' => ['pgsql', false, true],
    'PostgreSQL skip locked' => ['pgsql', true, 'for update skip locked'],
    'SQL Server fallback' => ['sqlsrv', true, true],
]);

it('serializes registrations using the same stable global lock key', function (string $driver) {
    $key = hash('sha256', 'versioned-storage-resource-registration');
    $insert = Mockery::mock(Builder::class);
    $lock = Mockery::mock(Builder::class);
    $this->connection->shouldReceive('table')->twice()->with('versioned_storage_locks')->andReturn($insert, $lock);
    $this->connection->shouldReceive('getDriverName')->andReturn($driver);
    $this->connection->shouldNotReceive('transaction', 'beginTransaction', 'commit', 'rollBack');
    $insert->shouldReceive('insertOrIgnore')->once()->with(['key' => $key])->globally()->ordered();
    $lock->shouldReceive('where')->once()->with('key', $key)->globally()->ordered()->andReturnSelf();

    if ($driver === 'sqlite') {
        $lock->shouldReceive('update')->once()->with(['key' => $key])->globally()->ordered();
    } else {
        $lock->shouldNotReceive('update');
    }
    $lock->shouldReceive('lockForUpdate')->once()->globally()->ordered()->andReturnSelf();
    $lock->shouldReceive('first')->once();

    $this->metadata->lockRegistration();
})->with(['sqlite', 'mysql', 'pgsql']);
