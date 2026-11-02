<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

class VersionedStorage
{
    public static function put(string $path, mixed $contents, bool $cleanup = false, ?string $disk = null): string
    {
        return self::handler($path, false, $disk)->write(
            fn (FilesystemAdapter $filesystem, string $version) => $filesystem->put($version, $contents),
            $cleanup,
        );
    }

    public static function get(string $path, ?string $version = null, ?string $disk = null): string
    {
        return self::handler($path, false, $disk)->get('', $version);
    }

    public static function exists(string $path, ?string $version = null, ?string $disk = null): bool
    {
        return self::handler($path, false, $disk)->exists('', $version);
    }

    /**
     * @return list<string>
     */
    public static function versions(string $path, ?string $disk = null): array
    {
        return self::handler($path, false, $disk)->versions();
    }

    /**
     * @param  Closure(FilesystemAdapter): void|null  $callback
     */
    public static function directory(
        string $path,
        ?Closure $callback = null,
        bool $cleanup = false,
        ?string $disk = null,
    ): string|VersionedStorageHandler {
        $handler = self::handler($path, true, $disk);

        return $callback === null ? $handler : $handler->write($callback, $cleanup);
    }

    private static function handler(string $path, bool $directory, ?string $disk): VersionedStorageHandler
    {
        $disk ??= config('laravel-versioned-storage.disk') ?? Storage::getDefaultDriver();

        return new VersionedStorageHandler($path, $disk, $directory);
    }
}
