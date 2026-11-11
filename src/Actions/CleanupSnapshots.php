<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Actions;

use Illuminate\Database\Connection;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use Throwable;

/**
 * Removes one resource's unwanted snapshots, outside application metadata transactions.
 * cleanup(): recover abandoned uploads, select retired versions, delete each eligible snapshot.
 * discard(): commit unpublished-stage discard intent, then delete/reconcile in another transaction.
 *
 * Handler-scoped and uncached. Private methods own each atomic eligibility check and update;
 * SnapshotDeletion owns physical removal. Failures retain durable work for retry. Cleanup
 * reports every deletion error, continues candidates, then rethrows the first; discard propagates.
 * Never retires readable history or deletes current versions.
 */
final class CleanupSnapshots
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceMetadata $metadata,
        private readonly RecoverUploads $recovery,
        private readonly SnapshotDeletion $deletion,
    ) {}

    public function cleanup(): void
    {
        if ($this->connection->transactionLevel() > 0 || MetadataTransaction::hasPdoTransaction($this->connection)) {
            throw new RuntimeException('Version cleanup cannot run inside an active metadata transaction.');
        }
        $error = null;
        $this->recovery->recover();
        $pending = $this->metadata->versions()->where('pending_delete', true)->pluck('version');

        foreach ($pending as $version) {
            try {
                $this->deleteRetired($version);
            } catch (Throwable $exception) {
                report($exception);
                $error ??= $exception;
            }
        }

        if ($error !== null) {
            throw $error;
        }
    }

    /**
     * Keep intent and deletion in separate transactions: deletion failure must not undo intent.
     * Missing stages are idempotent no-ops; published or still-protected uploads are rejected.
     */
    public function discard(string $version): void
    {
        MetadataTransaction::assertOutsideTransaction($this->connection);
        $this->markDiscarding($version);
        $this->deleteDiscarding($version);
    }

    private function markDiscarding(string $version): void
    {
        MetadataTransaction::run($this->connection, function () use ($version): void {
            $stage = $this->metadata->lockStage($version);

            if ($stage === null) {
                return;
            }

            if ($stage->state === 'published') {
                throw new RuntimeException("Published stage [{$version}] cannot be discarded.");
            }

            if ($stage->state === 'uploading' && $stage->cleanup_after > now()->toDateTimeString()) {
                throw new RuntimeException("Incomplete stage [{$version}] must retain its journal until the cleanup deadline.");
            }
            $this->metadata->stage($version)->update(['state' => 'discarding', 'cleanup_after' => now()]);
        });
    }

    /** Requires committed discard intent; keep the relock, deletion, and journal removal atomic. */
    private function deleteDiscarding(string $version): void
    {
        MetadataTransaction::run($this->connection, function () use ($version): void {
            $stage = $this->metadata->lockStage($version);

            if ($stage === null) {
                return;
            }

            if ($stage->state !== 'discarding') {
                throw new RuntimeException("Stage [{$version}] is not available for deletion.");
            }
            $this->deletion->delete($version);
            $this->connection->table('versioned_storage_operations')->where('version', $version)->delete();
            $this->metadata->stage($version)->delete();
        });
    }

    /** Hold resource/version locks through physical deletion and final metadata removal. */
    private function deleteRetired(string $version): void
    {
        MetadataTransaction::run($this->connection, function () use ($version): void {
            $resource = $this->metadata->lockResource();

            if ($resource === null || $resource->current_version === $version) {
                return;
            }
            $record = $this->metadata->resourceVersions($resource)->where('version', $version)
                ->where('pending_delete', true)->lockForUpdate()->first();

            if ($record === null) {
                return;
            }
            $this->deletion->delete($version);
            $this->metadata->resourceVersions($resource)->where('version', $version)->delete();
        });
    }
}
