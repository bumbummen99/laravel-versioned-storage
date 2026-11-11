<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Actions;

use Illuminate\Database\Connection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadDeadline;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

/**
 * Reserves a destination: 1. Capture disk configuration. 2. Commit a unique UUID reservation.
 * 3. Check physical absence with a supervised deadline, outside the reservation transaction.
 *
 * Handler-scoped. Requires successful admission and resource registration.
 * Returns UUID/configuration; collision or IO failure throws, retaining the permanent reservation.
 */
final class ReserveDestination
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceIdentity $resource,
        private readonly FilesystemAdapter $filesystem,
    ) {}

    /**
     * @param  array{timeout: int, fork: bool}  $execution
     * @return array{version: string, configuration: array<string, mixed>}
     */
    public function reserve(array $execution): array
    {
        $configuration = $this->filesystem->getConfig();

        if (! isset($configuration['driver']) && $this->filesystem->getAdapter() instanceof LocalFilesystemAdapter) {
            $configuration['driver'] = 'local';
        }

        $version = (string) Str::uuid();
        MetadataTransaction::run($this->connection, function () use ($version): void {
            $this->connection->table('versioned_storage_reservations')->insert(['version' => $version]);
        });
        $destination = $this->resource->path.'/'.$version;
        $deadline = new UploadDeadline($execution['timeout'], $execution['fork'], app(UploadClock::class));
        $exists = app(FilesystemOperationExecutor::class)->execute(
            static fn (FilesystemOperator $filesystem): bool => $filesystem->has($destination),
            $this->filesystem->getDriver(),
            $configuration,
            $deadline,
            $execution['fork'],
        );

        if ($exists) {
            throw new RuntimeException("Version path [{$destination}] already exists.");
        }

        return ['version' => $version, 'configuration' => $configuration];
    }
}
