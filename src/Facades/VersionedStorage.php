<?php

declare(strict_types=1);

namespace LaravelVersionedStorage\VersionedStorage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \LaravelVersionedStorage\VersionedStorage\VersionedStorage
 */
class VersionedStorage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LaravelVersionedStorage\VersionedStorage\VersionedStorage::class;
    }
}
