<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Actions;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

/**
 * Reclaims abandoned upload ownership: discover expired intents, lock/recheck each stage and
 * operation deadline, retain pending-delete metadata, then remove journals atomically.
 *
 * Handler-scoped, with a fresh primary-database scan per invocation.
 * Ready stages never expire. Does not delete files; cleanup owns that subsequent step.
 * retain() is an idempotent handoff nested in the stage transaction. Failures roll back the
 * handoff and stop this resource's scan without losing durable work.
 */
final class RecoverUploads
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceIdentity $resource,
        private readonly ResourceMetadata $metadata,
        private readonly RegisterResource $registration,
    ) {}

    public function recover(): void
    {
        MetadataTransaction::assertOutsideTransaction($this->connection);
        $candidates = $this->connection->table('versioned_storage_stages')->useWritePdo()
            ->whereIn('resource_id', $this->connection->table('versioned_storage_resources')->select('id')->where('key', $this->resource->key))
            ->whereIn('state', ['uploading', 'discarding'])
            ->where('cleanup_after', '<=', now())
            ->where('id', '<=', $this->connection->table('versioned_storage_stages')->useWritePdo()->max('id') ?? 0);

        $candidates->chunkById(100, function (Collection $stages): void {
            foreach ($stages as $candidate) {
                $this->recoverStage($candidate->version);
            }
        });
    }

    /** Record cleanup ownership without deleting files/journals; safe inside recovery's transaction. */
    public function retain(string $version): void
    {
        MetadataTransaction::run($this->connection, function () use ($version): void {
            $this->registration->register();
            $resource = $this->metadata->lockResource();

            if ($resource === null) {
                throw new RuntimeException("Unable to register rollback cleanup for [{$this->resource->path}].");
            }

            if ($this->metadata->versions()->where('version', $version)->exists()) {
                return;
            }
            $this->connection->table('versioned_storage_reservations')->insertOrIgnore(['version' => $version]);
            $this->connection->table('versioned_storage_versions')->insert([
                'resource_id' => $resource->id,
                'version' => $version,
                'created_at' => now(),
                'pending_delete' => true,
            ]);
        });
    }

    private function recoverStage(string $version): void
    {
        MetadataTransaction::run($this->connection, function () use ($version): void {
            $stage = $this->metadata->lockStage($version, true);

            if ($stage === null || ! in_array($stage->state, ['uploading', 'discarding'], true)
                || $stage->cleanup_after > now()->toDateTimeString()) {
                return;
            }

            if ($this->connection->table('versioned_storage_operations')->where('version', $version)
                ->where('cleanup_after', '>', now())->exists()) {
                return;
            }
            $this->retain($version);
            $this->connection->table('versioned_storage_operations')->where('version', $version)->delete();
            $this->metadata->stage($version)->delete();
        });
    }
}
