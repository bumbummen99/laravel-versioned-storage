<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Actions;

use Illuminate\Database\Connection;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

/**
 * Atomic publication: 1. Lock/check stage. 2. Lock resource. 3. Insert visible version.
 * 4. Set current reference. 5. Optionally retire older versions. 6. Mark stage published.
 *
 * Handler-scoped; each invocation uses the caller's connection and a five-attempt transaction.
 * Published retries are no-ops, even after retirement. No filesystem work occurs here.
 * Can nest inside upload completion or an application transaction; raw PDO transactions fail.
 */
final class PublishStage
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceIdentity $resource,
        private readonly ResourceMetadata $metadata,
    ) {}

    public function publish(string $version, bool $cleanup = false): void
    {
        if ($this->connection->transactionLevel() === 0 && MetadataTransaction::hasPdoTransaction($this->connection)) {
            throw new RuntimeException('Publish stages using Laravel-managed metadata transactions, not raw PDO transactions.');
        }

        MetadataTransaction::run($this->connection, function () use ($version, $cleanup): void {
            $stage = $this->metadata->lockStage($version);

            if ($stage === null) {
                throw new RuntimeException("Stage [{$version}] does not exist or was discarded.");
            }

            if ($stage->state === 'published') {
                return;
            }

            if ($stage->state !== 'ready') {
                throw new RuntimeException("Stage [{$version}] is not ready for publication.");
            }
            $resource = $this->metadata->lockResource();

            if ($resource === null) {
                throw new RuntimeException("Unable to register versioned resource [{$this->resource->path}].");
            }

            $this->connection->table('versioned_storage_versions')->insert([
                'resource_id' => $resource->id,
                'version' => $version,
                'created_at' => now(),
            ]);
            $this->connection->table('versioned_storage_resources')
                ->where('id', $resource->id)->update(['current_version' => $version]);

            if ($cleanup) {
                $this->metadata->resourceVersions($resource)->where('version', '!=', $version)->update(['pending_delete' => true]);
            }
            $this->metadata->stage($version)->update(['state' => 'published']);
        }, 5);
    }
}
