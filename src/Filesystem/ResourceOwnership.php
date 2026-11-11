<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Filesystem;

use Illuminate\Database\Connection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;
use Throwable;

/**
 * Enforces filesystem ownership for registration and snapshot deletion, including disk aliases.
 * Handler-scoped with the captured adapter. Calling workflows own synchronization.
 * Checks do not register resources, delete files, acquire registration locks, or open transactions.
 */
final class ResourceOwnership
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceIdentity $resource,
        private readonly FilesystemAdapter $filesystem,
    ) {}

    public function assertRegistrationAllowed(): void
    {
        $paths = $this->connection->table('versioned_storage_resources')->useWritePdo()
            ->where('key', '!=', $this->resource->key)->lockForUpdate()->get(['disk', 'path']);

        foreach ($paths as $resource) {
            if (($this->resource->path === $resource->path
                || str_starts_with($this->resource->path, $resource->path.'/')
                || str_starts_with($resource->path, $this->resource->path.'/'))
                && $this->sharesFilesystem((string) $resource->disk)) {
                throw new InvalidArgumentException(
                    "Versioned resource [{$this->resource->path}] overlaps registered resource [{$resource->path}] on disk [{$this->resource->disk}].",
                );
            }
        }
    }

    public function assertSnapshotRemovable(string $version, ?Throwable $previous = null): void
    {
        $path = $this->resource->path.'/'.$version;

        if ($this->resource->directory) {
            $resources = $this->connection->table('versioned_storage_resources')->useWritePdo()
                ->where('key', '!=', $this->resource->key)->get(['disk', 'path']);

            foreach ($resources as $resource) {
                if (($resource->path === $path || str_starts_with($resource->path, $path.'/'))
                    && $this->sharesFilesystem((string) $resource->disk)) {
                    throw new RuntimeException("Cannot remove version [{$version}]: it contains registered resource [{$resource->path}].", 0, $previous);
                }
            }
        }
    }

    private function sharesFilesystem(string $disk): bool
    {
        try {
            return Storage::disk($disk)->getAdapter() === $this->filesystem->getAdapter();
        } catch (InvalidArgumentException $exception) {
            // Resolve first so aliases registered with Storage::set() still participate.
            if (! empty(config("filesystems.disks.{$disk}.driver"))
                || $exception->getMessage() !== "Disk [{$disk}] does not have a configured driver.") {
                throw $exception;
            }

            return false;
        }
    }
}
