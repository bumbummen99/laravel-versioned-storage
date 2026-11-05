<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;
use Throwable;

class CleanupVersionsCommand extends Command
{
    protected $signature = 'laravel-versioned-storage:cleanup {--chunk=10 : Maximum concurrent snapshot deletions per batch}';

    protected $description = 'Recover interrupted uploads and retry pending version deletions.';

    public function handle(): int
    {
        $connection = DB::connection(config('laravel-versioned-storage.connection'));
        $size = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($size === false) {
            $this->error('The chunk size must be a positive integer.');

            return self::FAILURE;
        }

        $pdo = $connection->getRawPdo();

        if ($connection->transactionLevel() > 0 || ($pdo instanceof PDO && $pdo->inTransaction())) {
            $this->error('Version cleanup cannot run inside an active metadata transaction.');

            return self::FAILURE;
        }

        // Committed retirement is irreversible; publication has already drained protected readers.
        $failed = false;
        $staging = $connection->table('versioned_storage_resources')->useWritePdo()
            ->whereIn('id', $connection->table('versioned_storage_stages')->select('resource_id')
                ->whereIn('state', ['uploading', 'discarding'])->where('cleanup_after', '<=', now()))
            ->where('id', '<=', $connection->table('versioned_storage_resources')->useWritePdo()->max('id') ?? 0);
        $staging->chunkById($size, function (Collection $resources) use (&$failed): void {
            foreach ($resources as $resource) {
                try {
                    (new VersionedStorageHandler($resource->path, $resource->disk, (bool) $resource->is_directory))
                        ->recoverStages();
                } catch (Throwable $exception) {
                    $this->failure($exception);
                    $failed = true;
                }
            }
        });
        $pending = $connection->table('versioned_storage_versions as versions')
            ->useWritePdo()->join('versioned_storage_resources as resources', 'resources.id', '=', 'versions.resource_id')
            ->where('versions.pending_delete', true)
            ->where(fn (Builder $query) => $query->whereNull('resources.current_version')
                ->orWhereColumn('versions.version', '!=', 'resources.current_version'));
        // Bound this run so concurrent publications cannot extend it indefinitely.
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
                    $this->failure($exception);
                    $failed = true;
                }
            }

            if ($tasks === []) {
                continue;
            }

            try {
                $results = Concurrency::run($tasks);
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
                        $this->failure(new RuntimeException("Unable to remove version [{$result['version']}]: {$result['error']}"));
                        $failed = true;
                    } else {
                        $deleted[] = $ids[$result['version']];
                    }
                }

                if (count($seen) !== count($ids)) {
                    throw new RuntimeException('Missing cleanup worker results; metadata was retained.');
                }

                if ($deleted !== []) {
                    $connection->table('versioned_storage_versions')->whereIn('id', $deleted)
                        ->where('pending_delete', true)
                        ->whereNotIn('version', $connection->table('versioned_storage_resources')
                            ->select('current_version')->whereNotNull('current_version'))
                        ->delete();
                }
            } catch (Throwable $exception) {
                $this->failure($exception);
                $failed = true;
            }
        } while ($chunk->count() === $size);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function failure(Throwable $exception): void
    {
        report($exception);
        $this->error($exception->getMessage());
    }
}
