<?php

declare(strict_types=1);

namespace LaravelVersionedStorage\VersionedStorage\Console\Commands;

use Illuminate\Console\Command;

class VersionedStorageCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'laravel-versioned-storage:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package laravel-versioned-storage.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('VersionedStorage placeholder command executed.');

        return self::SUCCESS;
    }
}
