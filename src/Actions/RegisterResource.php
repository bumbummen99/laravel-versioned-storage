<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Actions;

use Illuminate\Database\Connection;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

/**
 * Establishes resource ownership: 1. Check registration. 2. Lock registration.
 * 3. Reject overlaps. 4. Persist the resource. Steps 2-4 share one retryable transaction.
 *
 * Handler-scoped, with no invocation state. Repeated registration is idempotent.
 * Does not reserve versions or touch the filesystem; conflicts propagate to the caller.
 */
final class RegisterResource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceIdentity $resource,
        private readonly ResourceMetadata $metadata,
        private readonly ResourceOwnership $ownership,
    ) {}

    public function register(): void
    {
        if ($this->metadata->exists()) {
            return;
        }

        MetadataTransaction::run($this->connection, function (): void {
            $this->metadata->lockRegistration();
            $this->ownership->assertRegistrationAllowed();
            $this->connection->table('versioned_storage_resources')->insertOrIgnore([
                'key' => $this->resource->key,
                'disk' => $this->resource->disk,
                'path' => $this->resource->path,
                'is_directory' => $this->resource->directory,
            ]);
        }, 5);
    }
}
