<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;
use Throwable;

class CleanupVersionsCommand extends Command
{
    protected $signature = 'laravel-versioned-storage:cleanup';

    protected $description = 'Retry pending version deletions without publishing new versions.';

    public function handle(): int
    {
        $connection = DB::connection(config('laravel-versioned-storage.connection'));
        $resources = $connection->table('versioned_storage_resources')
            ->whereIn('id', $connection->table('versioned_storage_versions')
                ->select('resource_id')->where('pending_delete', true))
            ->orderBy('id')->cursor();
        $failed = false;

        foreach ($resources as $resource) {
            try {
                (new VersionedStorageHandler($resource->path, $resource->disk, (bool) $resource->is_directory))
                    ->cleanup();
            } catch (Throwable $exception) {
                report($exception);
                $this->error($exception->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
