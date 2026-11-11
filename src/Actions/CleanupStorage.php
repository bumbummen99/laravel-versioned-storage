<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Actions;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Concurrency;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;
use Throwable;

/**
 * Runs cleanup across resources: recover expired uploads, then delete retired snapshots in
 * bounded batches, even if some recovery failed. Each batch delegates receipt acknowledgement
 * to ReconcileDeletions; workers do filesystem work only.
 *
 * Command-invocation scoped. The console adapter validates chunk size and transaction state.
 * Returns overall success; individual failures are delivered through the reporting callback.
 */
final class CleanupStorage
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ReconcileDeletions $reconciliation,
    ) {}

    /**
     * @param  positive-int  $size
     * @param  Closure(Throwable): void  $failure
     */
    public function cleanup(int $size, Closure $failure): bool
    {
        $recovered = $this->recoverResources($size, $failure);
        $deleted = $this->deleteRetiredBatches($size, $failure);

        return $recovered && $deleted;
    }

    /**
     * @param  positive-int  $size
     * @param  Closure(Throwable): void  $failure
     */
    private function recoverResources(int $size, Closure $failure): bool
    {
        $failed = false;
        $staging = $this->connection->table('versioned_storage_resources')->useWritePdo()
            ->whereIn('id', $this->connection->table('versioned_storage_stages')->select('resource_id')
                ->whereIn('state', ['uploading', 'discarding'])->where('cleanup_after', '<=', now()))
            ->where('id', '<=', $this->connection->table('versioned_storage_resources')->useWritePdo()->max('id') ?? 0);
        $staging->chunkById($size, function (Collection $resources) use (&$failed, $failure): void {
            foreach ($resources as $resource) {
                try {
                    (new VersionedStorageHandler($resource->path, $resource->disk, (bool) $resource->is_directory))
                        ->recoverStages();
                } catch (Throwable $exception) {
                    $failure($exception);
                    $failed = true;
                }
            }
        });

        return ! $failed;
    }

    /**
     * @param  positive-int  $size
     * @param  Closure(Throwable): void  $failure
     */
    private function deleteRetiredBatches(int $size, Closure $failure): bool
    {
        $failed = false;
        $pending = $this->connection->table('versioned_storage_versions as versions')
            ->useWritePdo()->join('versioned_storage_resources as resources', 'resources.id', '=', 'versions.resource_id')
            ->where('versions.pending_delete', true)
            ->where(fn (Builder $query) => $query->whereNull('resources.current_version')
                ->orWhereColumn('versions.version', '!=', 'resources.current_version'));
        $lastId = (int) (clone $pending)->max('versions.id');
        $after = 0;

        do {
            $chunk = (clone $pending)->where('versions.id', '>', $after)->where('versions.id', '<=', $lastId)
                ->orderBy('versions.id')->limit($size)
                ->get(['versions.id', 'versions.version', 'resources.path', 'resources.disk', 'resources.is_directory']);
            $tasks = [];
            $ids = [];

            foreach ($chunk as $record) {
                $after = (int) $record->id;

                try {
                    $tasks[] = (new VersionedStorageHandler($record->path, $record->disk, (bool) $record->is_directory))
                        ->deletionTask($record->version);
                    $ids[$record->version] = (int) $record->id;
                } catch (Throwable $exception) {
                    $failure($exception);
                    $failed = true;
                }
            }

            if ($tasks === []) {
                continue;
            }

            try {
                $results = Concurrency::run($tasks);
                $success = $this->reconciliation->reconcile($results, $ids, $failure);
                $failed = ! $success || $failed;
            } catch (Throwable $exception) {
                $failure($exception);
                $failed = true;
            }
        } while ($chunk->count() === $size);

        return ! $failed;
    }
}
