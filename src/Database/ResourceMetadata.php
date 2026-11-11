<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;
use stdClass;

/**
 * Provides fresh resource, version, and stage queries for one captured connection/resource.
 * Handler-scoped, with no cached records or state-transition decisions.
 * Resource and stage locks remain distinct; callers own transactions and lock release.
 * Lock methods never begin or commit transactions.
 */
final class ResourceMetadata
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceIdentity $resource,
    ) {}

    public function exists(): bool
    {
        return $this->connection->table('versioned_storage_resources')->useWritePdo()->where('key', $this->resource->key)->exists();
    }

    public function lockResource(): ?stdClass
    {
        $query = $this->connection->table('versioned_storage_resources')->where('key', $this->resource->key);

        // SQLite ignores FOR UPDATE, so acquire its write lock before reading a snapshot.
        if ($this->connection->getDriverName() === 'sqlite') {
            $query->update(['key' => $this->resource->key]);
        }

        return $query->lockForUpdate()->first();
    }

    public function resourceVersions(stdClass $resource): Builder
    {
        return $this->connection->table('versioned_storage_versions')->useWritePdo()->where('resource_id', $resource->id);
    }

    public function versions(): Builder
    {
        return $this->connection->table('versioned_storage_versions')->useWritePdo()
            ->whereIn('resource_id', $this->connection->table('versioned_storage_resources')
                ->select('id')->where('key', $this->resource->key));
    }

    public function stage(string $version): Builder
    {
        return $this->connection->table('versioned_storage_stages')->useWritePdo()->where('version', $version)
            ->whereIn('resource_id', $this->connection->table('versioned_storage_resources')->select('id')->where('key', $this->resource->key));
    }

    /** Skip locked stages only on supported engines; SQLite still requires write promotion. */
    public function lockStage(string $version, bool $skipLocked = false): ?stdClass
    {
        $query = $this->stage($version);

        if ($this->connection->getDriverName() === 'sqlite') {
            $query->update(['version' => $version]);
        }

        return $query->lock($skipLocked && in_array($this->connection->getDriverName(), ['mysql', 'pgsql'], true)
            ? 'for update skip locked' : true)->first();
    }

    public function lockRegistration(): void
    {
        $key = hash('sha256', 'versioned-storage-resource-registration');
        $this->connection->table('versioned_storage_locks')->insertOrIgnore(['key' => $key]);
        $lock = $this->connection->table('versioned_storage_locks')->where('key', $key);

        if ($this->connection->getDriverName() === 'sqlite') {
            $lock->update(['key' => $key]);
        }

        $lock->lockForUpdate()->first();
    }
}
