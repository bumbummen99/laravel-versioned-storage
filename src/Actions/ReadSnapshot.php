<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Actions;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Connection;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotFilesystem;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;
use stdClass;

/**
 * Protected read: 1. Lock resource. 2. Resolve a visible version. 3. Read/check its snapshot.
 * All steps share one transaction; filesystem access finishes before locks are released.
 *
 * Handler-scoped, with no cached selection. Missing reads throw; missing existence checks
 * return false. versions() lists readable identifiers from primary metadata without snapshot IO.
 * Does not publish versions, register resources, or retire snapshots.
 */
final class ReadSnapshot
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceIdentity $resource,
        private readonly ResourceMetadata $metadata,
        private readonly SnapshotFilesystem $snapshots,
    ) {}

    public function get(string $path, ?string $version = null): string
    {
        return MetadataTransaction::run($this->connection, function () use ($path, $version): string {
            $resource = $this->metadata->lockResource();
            $selected = $this->resolveVersion($version, $resource);

            if ($selected === null) {
                throw new FileNotFoundException("No matching version exists for [{$this->resource->path}].");
            }
            $contents = $this->snapshots->scoped($selected)->get($this->resource->directory ? $path : $selected);

            if ($contents === null) {
                throw new FileNotFoundException("File [{$path}] does not exist in version [{$selected}].");
            }

            return $contents;
        });
    }

    public function exists(string $path, ?string $version = null): bool
    {
        return MetadataTransaction::run($this->connection, function () use ($path, $version): bool {
            $resource = $this->metadata->lockResource();
            $selected = $this->resolveVersion($version, $resource);

            return $selected !== null
                && $this->snapshots->scoped($selected)->exists($this->resource->directory ? $path : $selected);
        });
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_values($this->metadata->versions()->where('pending_delete', false)->orderBy('id')->pluck('version')
            ->map(static fn (string $version): string => $version)->all());
    }

    private function resolveVersion(?string $version, ?stdClass $resource): ?string
    {
        if ($resource === null) {
            return null;
        }
        $version ??= $resource->current_version;

        return $version !== null && $this->metadata->resourceVersions($resource)->where('version', $version)
            ->where('pending_delete', false)->lockForUpdate()->exists() ? $version : null;
    }
}
