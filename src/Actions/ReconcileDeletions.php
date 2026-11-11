<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Actions;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * Acknowledges worker deletion: 1. Validate complete, unique, expected version/error receipts.
 * 2. Report worker failures. 3. Bulk-remove only successful, still-retired, non-current metadata.
 *
 * Command-invocation scoped and parent-only. Invalid/missing receipts throw before any deletion.
 * Returns false for reported worker errors; database failures propagate for retry.
 * Does not execute workers or assume that a missing file proves a writer has stopped.
 */
final class ReconcileDeletions
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * @param  array<array-key, mixed>  $results
     * @param  array<string, int>  $ids
     * @param  Closure(Throwable): void  $failure
     */
    public function reconcile(array $results, array $ids, Closure $failure): bool
    {
        $failed = false;
        $deleted = [];
        $seen = [];

        foreach ($results as $result) {
            if (! is_array($result) || ! isset($result['version'])
                || ! is_string($result['version']) || ! array_key_exists($result['version'], $ids)
                || isset($seen[$result['version']]) || ! array_key_exists('error', $result)
                || ($result['error'] !== null && ! is_string($result['error']))) {
                throw new RuntimeException('Invalid result returned by a cleanup worker; metadata was retained.');
            }

            $seen[$result['version']] = true;

            if ($result['error'] !== null) {
                $failure(new RuntimeException("Unable to remove version [{$result['version']}]: {$result['error']}"));
                $failed = true;
            } else {
                $deleted[] = $ids[$result['version']];
            }
        }

        if (count($seen) !== count($ids)) {
            throw new RuntimeException('Missing cleanup worker results; metadata was retained.');
        }

        if ($deleted !== []) {
            $this->connection->table('versioned_storage_versions')->whereIn('id', $deleted)
                ->where('pending_delete', true)
                ->whereNotIn('version', $this->connection->table('versioned_storage_resources')
                    ->select('current_version')->whereNotNull('current_version'))
                ->delete();
        }

        return ! $failed;
    }
}
