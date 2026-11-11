<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Actions\CleanupSnapshots;
use SkyRaptor\LaravelVersionedStorage\Actions\PublishStage;
use SkyRaptor\LaravelVersionedStorage\Actions\ReadSnapshot;
use SkyRaptor\LaravelVersionedStorage\Actions\RecoverUploads;
use SkyRaptor\LaravelVersionedStorage\Actions\RegisterResource;
use SkyRaptor\LaravelVersionedStorage\Actions\ReserveDestination;
use SkyRaptor\LaravelVersionedStorage\Actions\UploadSnapshot;
use SkyRaptor\LaravelVersionedStorage\Actions\UploadStages;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Database\UploadJournal;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotFilesystem;

/**
 * Consumer facade and composition root for writing, reading, and cleaning up one resource.
 * Captures identity, connection and adapter once; collaborators contain no cached resource state.
 * StagedVersion retains this handler. Database journals survive independently of PHP object lifetime.
 */
class VersionedStorageHandler
{
    private readonly UploadSnapshot $uploads;

    private readonly ReadSnapshot $reads;

    private readonly PublishStage $publication;

    private readonly RecoverUploads $recovery;

    private readonly CleanupSnapshots $cleanup;

    private readonly SnapshotDeletion $deletion;

    public function __construct(
        string $path,
        string $disk,
        bool $directory = true,
        ?Connection $connection = null,
    ) {
        $resource = new ResourceIdentity($path, $disk, $directory);
        $connection ??= DB::connection(config('laravel-versioned-storage.connection'));
        $metadata = new ResourceMetadata($connection, $resource);
        $journal = new UploadJournal($connection, $metadata);
        $filesystem = Storage::disk($disk);
        $ownership = new ResourceOwnership($connection, $resource, $filesystem, app('filesystem'), config());
        $registration = new RegisterResource($connection, $resource, $metadata, $ownership);
        $snapshots = new SnapshotFilesystem($filesystem, $resource);
        $this->deletion = new SnapshotDeletion($connection, $resource, $metadata, $ownership, $filesystem);

        $this->reads = new ReadSnapshot($connection, $resource, $metadata, $snapshots);
        $this->publication = new PublishStage($connection, $resource, $metadata);
        $this->uploads = new UploadSnapshot(
            $connection,
            $resource,
            $snapshots,
            $registration,
            new ReserveDestination($connection, $resource, $filesystem),
            new UploadStages($connection, $resource, $metadata, $journal, $this->publication, $this->deletion),
        );
        $this->recovery = new RecoverUploads($connection, $resource, $metadata, $registration);
        $this->cleanup = new CleanupSnapshots($connection, $metadata, $this->recovery, $this->deletion);
    }

    /**
     * Read from the current or a selected published, non-retired version under metadata locks.
     * Directory paths are relative to the snapshot; file resources always read their single file.
     * A missing readable version or file throws; no registration or publication occurs.
     */
    public function get(string $path, ?string $version = null): string
    {
        return $this->reads->get($path, $version);
    }

    /**
     * Check the current or a selected readable snapshot while holding the same locks as get().
     * Missing resources, unpublished/retired versions, and missing files return false.
     * Filesystem and database failures still propagate.
     */
    public function exists(string $path, ?string $version = null): bool
    {
        return $this->reads->exists($path, $version);
    }

    /**
     * List published, non-retired version identifiers in publication order.
     * Reads primary metadata only; does not check physical files or include unpublished stages.
     *
     * @return list<string>
     */
    public function versions(): array
    {
        return $this->reads->versions();
    }

    /**
     * Write a complete new snapshot, then atomically complete and publish it after callback success.
     * Requires no active metadata transaction. The callback stays in the parent; execution policy
     * determines supervision of individual filesystem operations, not the callback's total duration.
     * cleanup retires other published versions for later deletion; it does not delete their files.
     * Failures throw, retaining journals whenever writes may still be active.
     *
     * @param  Closure(FilesystemAdapter, string): mixed  $callback
     */
    public function write(Closure $callback, bool $cleanup = false): string
    {
        return $this->stage($callback, true, $cleanup)->version;
    }

    /**
     * Write a complete new snapshot and return its handle; never modifies an existing snapshot.
     * Requires no active metadata transaction. The parent callback must finish all snapshot work
     * before returning; a retained callback filesystem rejects further operations after closure.
     * Without publish, the ready stage has no automatic expiry and cleanup has no effect.
     * With publish, completion and publication are atomic; cleanup retires other published versions.
     * Failures throw and preserve uncertain writes for recovery.
     *
     * @param  Closure(FilesystemAdapter, string): mixed  $callback
     */
    public function stage(Closure $callback, bool $publish = false, bool $cleanup = false): StagedVersion
    {
        return new StagedVersion($this, $this->uploads->stage($callback, $publish, $cleanup));
    }

    /**
     * Publish a ready stage atomically, optionally inside a Laravel-managed metadata transaction.
     * cleanup retires other published versions without physical deletion. Published retries are
     * no-ops, even after retirement; missing or non-ready stages throw. No filesystem IO occurs.
     */
    public function publishStage(string $version, bool $cleanup = false): void
    {
        $this->publication->publish($version, $cleanup);
    }

    /**
     * Recover abandoned uploads, then delete eligible retired snapshots and their version metadata.
     * Requires no active metadata transaction; never retires readable history or deletes current.
     * Deletion failures retain retryable work, are reported, and do not stop other candidates;
     * the first deletion error is rethrown after the scan. Recovery failures stop before deletion.
     */
    public function cleanup(): void
    {
        $this->cleanup->cleanup();
    }

    /**
     * Commit unpublished-stage discard intent, then delete its snapshot and journals separately.
     * Requires no active metadata transaction. Published stages and uploads still protected by
     * their recovery deadline are rejected; missing stages are no-ops. A deletion failure leaves
     * committed intent for retry. This is not deletion of a published version.
     */
    public function discardStage(string $version): void
    {
        $this->cleanup->discard($version);
    }

    /**
     * Transfer eligible abandoned uploads to durable cleanup ownership without deleting files.
     * Requires no active metadata transaction. Stage and operation deadlines are rechecked
     * under locks; ready stages never expire. Repeated recovery is safe.
     */
    public function recoverStages(): void
    {
        $this->recovery->recover();
    }

    /**
     * Idempotently reserve a version and retain pending-delete ownership for maintenance retry.
     * The caller must establish reclamation eligibility; this does not check stage deadlines,
     * delete files/journals, or change existing version records. Consumers use discardStage().
     *
     * @internal
     */
    public function retainDiscard(string $version): void
    {
        $this->recovery->retain($version);
    }

    /**
     * Prepare an ownership-checked, filesystem-only worker for an eligible snapshot.
     * The caller establishes deletion eligibility and reconciles the returned receipt in the parent.
     * Preparation does not delete files; worker failures return an error receipt, not success.
     *
     * @internal
     *
     * @return Closure(): array{version: string, error: ?string}
     */
    public function deletionTask(string $version): Closure
    {
        return $this->deletion->prepare($version);
    }
}
