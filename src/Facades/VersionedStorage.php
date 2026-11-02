<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \SkyRaptor\LaravelVersionedStorage\VersionedStorage
 */
class VersionedStorage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SkyRaptor\LaravelVersionedStorage\VersionedStorage::class;
    }
}
