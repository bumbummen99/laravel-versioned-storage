<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Filesystem;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;
use Throwable;

/**
 * Removes physical snapshots after a calling workflow has established deletion eligibility.
 * delete() checks ownership under the caller's registration interlock and verifies removal.
 * prepare() checks ownership in the parent and creates a scalar-only worker performing remove().
 *
 * Handler-scoped with its captured adapter. Workers reconstruct the named disk and verify its
 * configuration. Never decides retirement/recovery eligibility or removes metadata; failures
 * leave the caller's durable retry records intact.
 */
final class SnapshotDeletion
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceIdentity $resource,
        private readonly ResourceMetadata $metadata,
        private readonly ResourceOwnership $ownership,
        private readonly FilesystemAdapter $filesystem,
    ) {}

    public function delete(string $version, ?Throwable $previous = null): void
    {
        if ($this->connection->transactionLevel() > 0) {
            $this->metadata->lockRegistration();
        }

        $this->ownership->assertSnapshotRemovable($version, $previous);
        self::remove($this->filesystem, $this->resource->path.'/'.$version, $this->resource->directory, $previous);
    }

    /**
     * Check ownership before dispatch; capture no services or connections in the returned closure.
     *
     * @return Closure(): array{version: string, error: ?string}
     */
    public function prepare(string $version): Closure
    {
        $this->ownership->assertSnapshotRemovable($version);
        $disk = $this->resource->disk;
        $path = $this->resource->path.'/'.$version;
        $directory = $this->resource->directory;
        $configuration = hash('sha256', json_encode($this->filesystem->getConfig(), JSON_THROW_ON_ERROR));

        return static function () use ($disk, $path, $version, $directory, $configuration): array {
            try {
                $filesystem = Storage::disk($disk);

                if (hash('sha256', json_encode($filesystem->getConfig(), JSON_THROW_ON_ERROR)) !== $configuration) {
                    throw new RuntimeException("Disk [{$disk}] has different configuration in the cleanup worker.");
                }

                self::remove($filesystem, $path, $directory);

                return ['version' => $version, 'error' => null];
            } catch (Throwable $exception) {
                return ['version' => $version, 'error' => $exception->getMessage()];
            }
        };
    }

    /**
     * Filesystem-only primitive, safe in workers after parent ownership/eligibility checks.
     * A refused or incomplete deletion throws, preserving the original failure.
     */
    public static function remove(FilesystemAdapter $filesystem, string $path, bool $directory, ?Throwable $previous = null): void
    {
        $deleted = $directory ? $filesystem->deleteDirectory($path) : $filesystem->delete($path);

        if (! $deleted || $filesystem->exists($path)) {
            throw new RuntimeException("Unable to remove version snapshot [{$path}].", 0, $previous);
        }
    }
}
