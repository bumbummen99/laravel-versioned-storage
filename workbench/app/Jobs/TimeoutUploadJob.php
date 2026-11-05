<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

class TimeoutUploadJob implements ShouldQueue
{
    public function __construct(public ?int $timeout, public bool $slow) {}

    public function handle(): void
    {
        VersionedStorage::directory('queued', function (FilesystemAdapter $files): void {
            $files->put('partial', 'contents');

            if ($this->slow) {
                sleep(5);
            }
            $files->put('finished', 'contents');
        });
    }
}
