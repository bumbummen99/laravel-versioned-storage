<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Tests\Support;

use SkyRaptor\LaravelVersionedStorage\UploadContext;

class FpmUploadContext extends UploadContext
{
    protected function sapi(): string
    {
        return 'fpm-fcgi';
    }
}
