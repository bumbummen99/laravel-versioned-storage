<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use Closure;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use League\Flysystem\WhitespacePathNormalizer;
use RuntimeException;
use Throwable;

class VersionedStorageHandler
{
    private readonly string $path;

    private readonly string $key;

    public function __construct(
        string $path,
        private readonly string $disk,
        private readonly bool $directory = true,
    ) {
        $this->path = (new WhitespacePathNormalizer)->normalizePath($path);

        if ($this->path === '') {
            throw new InvalidArgumentException('A versioned storage path must not be empty.');
        }

        $this->key = hash('sha256', $disk."\0".$this->path."\0".($directory ? 'directory' : 'file'));
    }

    public function get(string $path, ?string $version = null): string
    {
        $version = $this->resolveVersion($version);

        if ($version === null) {
            throw new FileNotFoundException("No matching version exists for [{$this->path}].");
        }

        $contents = $this->filesystem($version)->get($this->directory ? $path : $version);

        if ($contents === null) {
            throw new FileNotFoundException("File [{$path}] does not exist in version [{$version}].");
        }

        return $contents;
    }

    public function exists(string $path, ?string $version = null): bool
    {
        $version = $this->resolveVersion($version);

        return $version !== null
            && $this->filesystem($version)->exists($this->directory ? $path : $version);
    }

    /**
     * @return list<string>
     */
    public function versions(): array
    {
        return array_values($this->versionQuery()->orderBy('id')->pluck('version')
            ->map(static fn (string $version): string => $version)->all());
    }

    /**
     * @param  Closure(FilesystemAdapter, string): mixed  $callback
     */
    public function write(Closure $callback, bool $cleanup = false): string
    {
        $version = (string) Str::uuid();

        try {
            $filesystem = $this->filesystem($version);

            if ($this->directory) {
                $filesystem->makeDirectory('');
            }

            $callback($filesystem, $version);
            $oldVersions = $this->publish($version, $cleanup);
        } catch (Throwable $exception) {
            $this->removeVersion($version, $exception);

            throw $exception;
        }

        foreach ($oldVersions as $oldVersion) {
            $this->removeVersion($oldVersion);
        }

        return $version;
    }

    private function filesystem(string $version): FilesystemAdapter
    {
        return new VersionedFilesystem(
            Storage::disk($this->disk),
            $this->directory ? $this->path.'/'.$version : $this->path,
        );
    }

    /**
     * @return list<string>
     */
    private function publish(string $version, bool $cleanup): array
    {
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $version, $cleanup): array {
            // The persistent resource row serializes even concurrent first publications.
            $connection->table('versioned_storage_resources')->insertOrIgnore([
                'key' => $this->key,
                'disk' => $this->disk,
                'path' => $this->path,
                'is_directory' => $this->directory,
            ]);

            $resource = $connection->table('versioned_storage_resources')
                ->where('key', $this->key)->lockForUpdate()->first();

            if ($resource === null) {
                throw new RuntimeException("Unable to register versioned resource [{$this->path}].");
            }

            $oldVersions = $cleanup ? $this->versions() : [];

            $connection->table('versioned_storage_versions')->insert([
                'resource_id' => $resource->id,
                'version' => $version,
                'created_at' => now(),
            ]);

            $connection->table('versioned_storage_resources')
                ->where('id', $resource->id)->update(['current_version' => $version]);

            if ($oldVersions !== []) {
                $this->versionQuery()->whereIn('version', $oldVersions)->delete();
            }

            return $oldVersions;
        }, 5);
    }

    private function resolveVersion(?string $version): ?string
    {
        if ($version !== null) {
            return $this->versionQuery()->where('version', $version)->exists() ? $version : null;
        }

        return $this->connection()->table('versioned_storage_resources')
            ->where('key', $this->key)->value('current_version');
    }

    private function versionQuery(): Builder
    {
        return $this->connection()->table('versioned_storage_versions')
            ->whereIn('resource_id', $this->connection()->table('versioned_storage_resources')
                ->select('id')->where('key', $this->key));
    }

    private function connection(): Connection
    {
        return DB::connection(config('laravel-versioned-storage.connection'));
    }

    private function removeVersion(string $version, ?Throwable $previous = null): void
    {
        $filesystem = Storage::disk($this->disk);
        $path = $this->path.'/'.$version;
        $deleted = $this->directory ? $filesystem->deleteDirectory($path) : $filesystem->delete($path);

        if (! $deleted) {
            throw new RuntimeException("Unable to remove version [{$version}] of [{$this->path}].", 0, $previous);
        }
    }
}
