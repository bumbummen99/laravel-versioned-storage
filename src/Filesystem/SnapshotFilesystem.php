<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Filesystem;

use Illuminate\Filesystem\FilesystemAdapter;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Adapters\VersionedFilesystem;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadOperation;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

/**
 * Constructs snapshot views from the handler's captured adapter.
 * Handler-scoped; each scoped view is fresh and may carry one callback's UploadOperation.
 * Does not reserve destinations, delete snapshots, access metadata, or manage transactions.
 */
final class SnapshotFilesystem
{
    public function __construct(
        private readonly FilesystemAdapter $filesystem,
        private readonly ResourceIdentity $resource,
    ) {}

    public function scoped(string $version, ?UploadOperation $operation = null): FilesystemAdapter
    {
        return new VersionedFilesystem(
            $this->filesystem,
            $this->resource->directory ? $this->resource->path.'/'.$version : $this->resource->path,
            $operation,
        );
    }
}
