<?php

namespace SkyRaptor\LaravelVersionedStorage\Commands;

use Illuminate\Console\Command;

class LaravelVersionedStorageCommand extends Command
{
    public $signature = 'laravel-versioned-storage';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
