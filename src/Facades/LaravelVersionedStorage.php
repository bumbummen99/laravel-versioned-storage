<?php

namespace SkyRaptor\LaravelVersionedStorage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \SkyRaptor\LaravelVersionedStorage\LaravelVersionedStorage
 */
class LaravelVersionedStorage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SkyRaptor\LaravelVersionedStorage\LaravelVersionedStorage::class;
    }
}
