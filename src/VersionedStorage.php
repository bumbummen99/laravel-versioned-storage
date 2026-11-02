<?php

namespace SkyRaptor\LaravelVersionedStorage;

use Closure;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VersionedStorage
{
    private const STORAGE_PREFIX = '.versioned-storage';

    public static function put(string $path, mixed $contents, bool $cleanup = false, ?string $disk = null): string
    {
        $diskName = self::diskName($disk);
        $version = (string) Str::uuid();
        $versionPath = self::versionPath($diskName, $path, 'file', $version);
        $filesystem = Storage::disk($diskName);

        if (! $filesystem->put($versionPath.'/content', $contents)) {
            throw new RuntimeException("Unable to write versioned file [{$path}].");
        }

        return self::saveVersion($diskName, $path, 'file', $version, $cleanup);
    }

    public static function get(string $path, ?string $version = null, ?string $disk = null): string
    {
        $diskName = self::diskName($disk);
        $version = self::resolveVersion($diskName, $path, 'file', $version);

        if ($version === null) {
            throw new FileNotFoundException("No version exists for [{$path}].");
        }

        return Storage::disk($diskName)->get(
            self::versionPath($diskName, $path, 'file', $version).'/content'
        );
    }

    public static function exists(string $path, ?string $version = null, ?string $disk = null): bool
    {
        $diskName = self::diskName($disk);
        $version = self::resolveVersion($diskName, $path, 'file', $version);

        return $version !== null && Storage::disk($diskName)->exists(
            self::versionPath($diskName, $path, 'file', $version).'/content'
        );
    }

    /**
     * @return list<string>
     */
    public static function versions(string $path, ?string $disk = null): array
    {
        return self::versionList(self::diskName($disk), $path, 'file');
    }

    public static function directory(
        string $path,
        ?Closure $callback = null,
        bool $cleanup = false,
        ?string $disk = null
    ): string|VersionedStorageHandler {
        $diskName = self::diskName($disk);

        if ($callback === null) {
            return new VersionedStorageHandler($path, $diskName);
        }

        $version = (string) Str::uuid();
        $versionPath = self::versionPath($diskName, $path, 'directory', $version);
        $scopedFilesystem = self::scopedFilesystem($path, $version, $diskName);

        try {
            $callback($scopedFilesystem);
        } catch (Throwable $exception) {
            Storage::disk($diskName)->deleteDirectory($versionPath);

            throw $exception;
        }

        return self::saveVersion($diskName, $path, 'directory', $version, $cleanup);
    }

    public static function scopedFilesystem(string $path, string $version, string $disk): Filesystem
    {
        return new VersionedFilesystem(
            Storage::disk($disk),
            self::versionPath($disk, $path, 'directory', $version)
        );
    }

    public static function directoryVersion(string $path, ?string $version, string $disk): ?string
    {
        return self::resolveVersion($disk, $path, 'directory', $version);
    }

    public static function directoryVersions(string $path, string $disk): array
    {
        return self::versionList($disk, $path, 'directory');
    }

    private static function saveVersion(
        string $disk,
        string $path,
        string $type,
        string $version,
        bool $cleanup
    ): string {
        $table = self::table();
        $resourcePath = self::resourcePath($path, $type);
        $connection = DB::connection(config('versioned-storage.database'));

        try {
            $oldVersions = $connection->transaction(function () use ($connection, $table, $disk, $resourcePath, $version, $cleanup) {
                $oldVersions = $cleanup
                    ? $connection->table($table)
                        ->where('disk', $disk)
                        ->where('path', $resourcePath)
                        ->pluck('version')
                        ->all()
                    : [];

                $connection->table($table)
                    ->where('disk', $disk)
                    ->where('path', $resourcePath)
                    ->update(['is_current' => false, 'updated_at' => now()]);

                $connection->table($table)->insert([
                    'disk' => $disk,
                    'path' => $resourcePath,
                    'version' => $version,
                    'is_current' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($cleanup) {
                    $connection->table($table)
                        ->where('disk', $disk)
                        ->where('path', $resourcePath)
                        ->where('version', '!=', $version)
                        ->delete();
                }

                return $oldVersions;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->deleteDirectory(
                self::versionPath($disk, $path, $type, $version)
            );

            throw $exception;
        }

        if ($cleanup) {
            foreach ($oldVersions as $oldVersion) {
                if ($oldVersion !== $version && ! Storage::disk($disk)->deleteDirectory(
                    self::versionPath($disk, $path, $type, $oldVersion)
                )) {
                    throw new RuntimeException("Unable to remove previous version [{$oldVersion}] of [{$path}].");
                }
            }
        }

        return $version;
    }

    private static function currentVersion(string $disk, string $path, string $type): ?string
    {
        return DB::connection(config('versioned-storage.database'))
            ->table(self::table())
            ->where('disk', $disk)
            ->where('path', self::resourcePath($path, $type))
            ->where('is_current', true)
            ->value('version');
    }

    private static function resolveVersion(string $disk, string $path, string $type, ?string $version): ?string
    {
        if ($version === null) {
            return self::currentVersion($disk, $path, $type);
        }

        return self::hasVersion($disk, $path, $type, $version) ? $version : null;
    }

    private static function hasVersion(string $disk, string $path, string $type, string $version): bool
    {
        return DB::connection(config('versioned-storage.database'))
            ->table(self::table())
            ->where('disk', $disk)
            ->where('path', self::resourcePath($path, $type))
            ->where('version', $version)
            ->exists();
    }

    /**
     * @return list<string>
     */
    private static function versionList(string $disk, string $path, string $type): array
    {
        return DB::connection(config('versioned-storage.database'))
            ->table(self::table())
            ->where('disk', $disk)
            ->where('path', self::resourcePath($path, $type))
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('version')
            ->all();
    }

    private static function diskName(?string $disk): string
    {
        return $disk ?? config('versioned-storage.disk') ?? config('filesystems.default');
    }

    private static function table(): string
    {
        return config('versioned-storage.table', 'versioned_storage_entry');
    }

    private static function resourcePath(string $path, string $type): string
    {
        return "{$type}:".hash('sha256', $path);
    }

    private static function versionPath(string $disk, string $path, string $type, string $version): string
    {
        return self::STORAGE_PREFIX.'/'.hash('sha256', $disk."\0".$type."\0".$path).'/'.$version;
    }
}
