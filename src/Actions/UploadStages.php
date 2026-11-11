<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Actions;

use Illuminate\Database\Connection;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Database\UploadJournal;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadDeadline;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadOperation;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;
use Throwable;

/**
 * Applies stage-transition rules: committed intent, atomic completion, and sticky failure.
 * The writing workflow calls begin() before IO, then complete() or fail() after closing its context.
 * Completion may publish within the same transaction; failure never removes uncertain writes.
 * Owns whole-snapshot decisions/updates; UploadJournal persists individual operation intent.
 *
 * Handler-scoped, sharing its connection and resource metadata. No stage state is cached.
 * Each begin() returns a fresh callback context; durable journals outlive this object.
 */
final class UploadStages
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceIdentity $resource,
        private readonly ResourceMetadata $metadata,
        private readonly UploadJournal $journal,
        private readonly PublishStage $publication,
        private readonly SnapshotDeletion $deletion,
    ) {}

    /**
     * Commit intent before constructing the callback context. Requires a reserved destination.
     *
     * @param  array{timeout: int, fork: bool}  $execution
     * @param  array<string, mixed>  $configuration
     */
    public function begin(string $version, array $execution, array $configuration, int $grace): UploadOperation
    {
        $cleanupAfter = (new UploadDeadline($execution['timeout'], $execution['fork'], app(UploadClock::class)))->recoveryAfter($grace);
        MetadataTransaction::run($this->connection, function () use ($version, $cleanupAfter): void {
            $resource = $this->metadata->lockResource();

            if ($resource === null) {
                throw new RuntimeException("Unable to register versioned resource [{$this->resource->path}].");
            }
            $this->connection->table('versioned_storage_stages')->insert([
                'resource_id' => $resource->id, 'version' => $version, 'state' => 'uploading', 'cleanup_after' => $cleanupAfter,
            ]);
        });

        return $this->journal->operation($version, $execution, $configuration, $grace);
    }

    /**
     * Requires a healthy, closed callback. Lock/check, mark ready, and optionally publish atomically.
     * Failure rolls back completion; a completed unpublished stage has no expiry.
     */
    public function complete(string $version, bool $publish, bool $cleanup): void
    {
        MetadataTransaction::run($this->connection, function () use ($version, $publish, $cleanup): void {
            $stage = $this->metadata->lockStage($version);

            if ($stage === null || $stage->state !== 'uploading' || $stage->failed
                || $this->connection->table('versioned_storage_operations')->where('version', $version)->exists()) {
                throw new RuntimeException("Stage [{$version}] failed or was reclaimed before upload completion.");
            }
            $this->metadata->stage($version)->update(['state' => 'ready']);

            if ($publish) {
                $this->publication->publish($version, $cleanup);
            }
        });
    }

    /**
     * Protect committed versions; otherwise mark failure and delete only without uncertain operations.
     * Retains journals/deadlines and rethrows. If deletion fails, reports the original error
     * and propagates the deletion exception.
     */
    public function fail(string $version, Throwable $exception): never
    {
        if (! $this->metadata->versions()->where('version', $version)->exists()) {
            try {
                MetadataTransaction::run($this->connection, function () use ($version, $exception): void {
                    $stage = $this->metadata->lockStage($version);

                    if ($stage !== null && $stage->state === 'uploading') {
                        $this->metadata->stage($version)->update(['failed' => true]);

                        if (! $this->connection->table('versioned_storage_operations')->where('version', $version)->exists()) {
                            $this->deletion->delete($version, $exception);
                        }
                    }
                });
            } catch (Throwable $deletionException) {
                report($exception);

                throw $deletionException;
            }
        }

        throw $exception;
    }
}
