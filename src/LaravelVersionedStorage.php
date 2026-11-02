<?php

namespace SkyRaptor\LaravelVersionedStorage;

use Closure;
use Illuminate\Support\Facades\Storage;

class LaravelVersionedStorage
{
    public function disk(string $name)
    {
        return Storage::disk($name);
    }

    public function put(string $path, mixed $contents, bool $cleanup = false, ?string $disk = null): string
    {
        return VersionedStorage::put($path, $contents, $cleanup, $disk);
    }

    public function get(string $path, ?string $version = null, ?string $disk = null): string
    {
        return VersionedStorage::get($path, $version, $disk);
    }

    public function exists(string $path, ?string $version = null, ?string $disk = null): bool
    {
        return VersionedStorage::exists($path, $version, $disk);
    }

    public function versions(string $path, ?string $disk = null): array
    {
        return VersionedStorage::versions($path, $disk);
    }

    public function directory(
        string $path,
        ?Closure $callback = null,
        bool $cleanup = false,
        ?string $disk = null
    ): string|VersionedStorageHandler {
        return VersionedStorage::directory($path, $callback, $cleanup, $disk);
    }
}
