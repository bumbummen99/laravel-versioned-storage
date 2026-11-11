<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\Actions\ReadSnapshot;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotFilesystem;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

function expectReadSnapshotLookup(Builder $query, string $version, bool $visible): void
{
    $query->shouldReceive('useWritePdo')->once()->andReturnSelf();
    $query->shouldReceive('where')->once()->with('resource_id', 42)->andReturnSelf();
    $query->shouldReceive('where')->once()->with('version', $version)->andReturnSelf();
    $query->shouldReceive('where')->once()->with('pending_delete', false)->andReturnSelf();
    $query->shouldReceive('lockForUpdate')->once()->andReturnSelf();
    $query->shouldReceive('exists')->once()->andReturn($visible);
}

beforeEach(function (): void {
    $this->resource = new ResourceIdentity('reports', 'local', true);
    $this->row = (object) ['id' => 42, 'current_version' => 'current'];
    $this->resources = Mockery::mock(Builder::class);
    $this->resources->shouldReceive('where')->with('key', $this->resource->key)->andReturnSelf()->byDefault();
    $this->resources->shouldReceive('lockForUpdate')->andReturnSelf()->byDefault();
    $this->resources->shouldReceive('first')->andReturn($this->row)->byDefault();
    $this->versions = Mockery::mock(Builder::class);
    $this->connection = Mockery::mock(Connection::class);
    $this->connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $this->connection->shouldReceive('table')->with('versioned_storage_resources')->andReturn($this->resources);
    $this->connection->shouldReceive('table')->with('versioned_storage_versions')->andReturn($this->versions)->byDefault();
    $this->connection->shouldReceive('transactionLevel')->andReturn(0);
    $this->connection->shouldReceive('getRawPdo')->andReturnNull();
    $this->insideTransaction = false;
    $this->connection->shouldReceive('transaction')->andReturnUsing(function (Closure $callback, int $attempts): mixed {
        expect($attempts)->toBe(1);
        $this->insideTransaction = true;

        try {
            return $callback();
        } finally {
            $this->insideTransaction = false;
        }
    });
    $this->operator = Mockery::mock(FilesystemOperator::class);
    $this->disk = new FilesystemAdapter($this->operator, Mockery::mock(FlysystemAdapter::class));
    $this->reads = new ReadSnapshot(
        $this->connection, $this->resource, new ResourceMetadata($this->connection, $this->resource),
        new SnapshotFilesystem($this->disk, $this->resource),
    );
});

afterEach(function (): void {
    Mockery::close();
});

it('throws for a read of an unregistered resource without consulting versions or disk', function () {
    $this->resources->shouldReceive('first')->once()->andReturnNull();
    $this->connection->shouldNotReceive('table')->with('versioned_storage_versions');
    $this->operator->shouldNotReceive('read');

    expect(fn () => $this->reads->get('one'))->toThrow(FileNotFoundException::class, 'No matching version exists');
});

it('returns false for an existence check of an unregistered resource without IO', function () {
    $this->resources->shouldReceive('first')->once()->andReturnNull();
    $this->connection->shouldNotReceive('table')->with('versioned_storage_versions');
    $this->operator->shouldNotReceive('has');

    expect($this->reads->exists('one'))->toBeFalse();
});

it('throws when a registered resource has no current version', function () {
    $this->row->current_version = null;
    $this->connection->shouldNotReceive('table')->with('versioned_storage_versions');
    $this->operator->shouldNotReceive('read');

    expect(fn () => $this->reads->get('one'))->toThrow(FileNotFoundException::class);
});

it('returns false when a registered resource has no current version', function () {
    $this->row->current_version = null;
    $this->connection->shouldNotReceive('table')->with('versioned_storage_versions');
    $this->operator->shouldNotReceive('has');

    expect($this->reads->exists('one'))->toBeFalse();
});

it('rejects a version that is not visible in this resource', function () {
    expectReadSnapshotLookup($this->versions, 'retired-or-foreign', false);
    $this->operator->shouldNotReceive('read');

    expect(fn () => $this->reads->get('one', 'retired-or-foreign'))->toThrow(FileNotFoundException::class);
});

it('does not check physical existence of a version that is not visible in this resource', function () {
    expectReadSnapshotLookup($this->versions, 'retired-or-foreign', false);
    $this->operator->shouldNotReceive('has');

    expect($this->reads->exists('one', 'retired-or-foreign'))->toBeFalse();
});

it('reads a directory member before leaving its metadata transaction', function () {
    expectReadSnapshotLookup($this->versions, 'current', true);
    $this->operator->shouldReceive('read')->once()->with('reports/current/one')->andReturnUsing(function (): string {
        expect($this->insideTransaction)->toBeTrue();

        return 'contents';
    });

    expect($this->reads->get('one'))->toBe('contents')->and($this->insideTransaction)->toBeFalse();
});

it('reads a single file by version rather than treating its input as a directory member', function () {
    $file = new ResourceIdentity('reports', 'local', false);
    $this->resources->shouldReceive('where')->with('key', $file->key)->andReturnSelf();
    $reads = new ReadSnapshot(
        $this->connection, $file, new ResourceMetadata($this->connection, $file),
        new SnapshotFilesystem($this->disk, $file),
    );
    expectReadSnapshotLookup($this->versions, 'current', true);
    $this->operator->shouldReceive('read')->once()->with('reports/current')->andReturn('file contents');

    expect($reads->get('ignored-member'))->toBe('file contents');
});

it('honors an explicit historical version instead of the current pointer', function () {
    expectReadSnapshotLookup($this->versions, 'older', true);
    $this->operator->shouldReceive('read')->once()->with('reports/older/one')->andReturn('earlier contents');

    expect($this->reads->get('one', 'older'))->toBe('earlier contents');
});

it('can select explicit history even when the current pointer is empty', function () {
    $this->row->current_version = null;
    expectReadSnapshotLookup($this->versions, 'older', true);
    $this->operator->shouldReceive('read')->once()->with('reports/older/one')->andReturn('earlier contents');

    expect($this->reads->get('one', 'older'))->toBe('earlier contents');
});

it('returns the physical existence result while still inside the transaction', function (bool $present) {
    expectReadSnapshotLookup($this->versions, 'current', true);
    $this->operator->shouldReceive('has')->once()->with('reports/current/one')->andReturnUsing(function () use ($present): bool {
        expect($this->insideTransaction)->toBeTrue();

        return $present;
    });

    expect($this->reads->exists('one'))->toBe($present)->and($this->insideTransaction)->toBeFalse();
})->with(['present file' => true, 'missing file' => false]);

it('propagates physical read errors and leaves the transaction callback', function () {
    $failure = new LogicException('storage unavailable');
    expectReadSnapshotLookup($this->versions, 'current', true);
    $this->operator->shouldReceive('read')->once()->with('reports/current/one')->andThrow($failure);

    expect(fn () => $this->reads->get('one'))->toThrow($failure);
    expect($this->insideTransaction)->toBeFalse();
});

it('lists visible versions in insertion order with consecutive result keys', function () {
    $this->resources->shouldReceive('select')->once()->with('id')->andReturnSelf();
    $this->versions->shouldReceive('useWritePdo')->once()->andReturnSelf();
    $this->versions->shouldReceive('whereIn')->once()->with('resource_id', $this->resources)->andReturnSelf();
    $this->versions->shouldReceive('where')->once()->with('pending_delete', false)->andReturnSelf();
    $this->versions->shouldReceive('orderBy')->once()->with('id')->andReturnSelf();
    $this->versions->shouldReceive('pluck')->once()->with('version')->andReturn(new Collection([7 => 'older', 12 => 'current']));

    expect($this->reads->versions())->toBe(['older', 'current']);
});
