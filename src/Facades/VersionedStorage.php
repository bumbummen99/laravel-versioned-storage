<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Laravel facade forwarding to consumer entry points; owns no resource lifecycle state.
 *
 * @see \SkyRaptor\LaravelVersionedStorage\VersionedStorage
 */
class VersionedStorage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SkyRaptor\LaravelVersionedStorage\VersionedStorage::class;
    }
}
