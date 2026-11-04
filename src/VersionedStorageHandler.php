<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use Closure;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use League\Flysystem\WhitespacePathNormalizer;
use RuntimeException;
use stdClass;
use Throwable;

class VersionedStorageHandler
{
    private readonly string $path;

    private readonly string $key;

    private readonly Connection $database;

    private readonly FilesystemAdapter $diskFilesystem;

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
        $this->database = DB::connection(config('laravel-versioned-storage.connection'));
        $this->diskFilesystem = Storage::disk($disk);
    }

    public function get(string $path, ?string $version = null): string
    {
        return $this->transaction(function () use ($path, $version): string {
            $resource = $this->lockResource();
            $selected = $this->resolveVersion($version, $resource);

            if ($selected === null) {
                throw new FileNotFoundException("No matching version exists for [{$this->path}].");
            }

            $contents = $this->filesystem($selected)->get($this->directory ? $path : $selected);

            if ($contents === null) {
                throw new FileNotFoundException("File [{$path}] does not exist in version [{$selected}].");
            }

            return $contents;
        });
    }

    public function exists(string $path, ?string $version = null): bool
    {
        return $this->transaction(function () use ($path, $version): bool {
            $resource = $this->lockResource();
            $selected = $this->resolveVersion($version, $resource);

            return $selected !== null
                && $this->filesystem($selected)->exists($this->directory ? $path : $selected);
        });
    }

    /**
     * @return list<string>
     */
    public function versions(): array
    {
        return array_values($this->versionQuery()->where('pending_delete', false)->orderBy('id')->pluck('version')
            ->map(static fn (string $version): string => $version)->all());
    }

    /**
     * @param  Closure(FilesystemAdapter, string): mixed  $callback
     */
    public function write(Closure $callback, bool $cleanup = false): string
    {
        $this->registerResource();
        $version = (string) Str::uuid();

        $reserve = function () use ($version): void {
            $this->connection()->table('versioned_storage_reservations')->insert(['version' => $version]);
        };

        if ($this->connection()->transactionLevel() > 0) {
            $this->transaction($reserve);
        } else {
            $reserve();
        }

        if ($this->diskFilesystem->exists($this->path.'/'.$version)) {
            throw new RuntimeException("Version path [{$this->path}/{$version}] already exists.");
        }

        try {
            $filesystem = $this->filesystem($version);

            if ($this->directory) {
                $filesystem->makeDirectory('');
            }

            $callback($filesystem, $version);
        } catch (Throwable $exception) {
            $this->removeVersion($version, $exception);

            throw $exception;
        }

        try {
            $this->publish($version, $cleanup);
        } catch (Throwable $exception) {
            // A transaction listener may throw after the database has committed.
            if ($this->versionQuery()->where('version', $version)->exists()) {
                $this->deferIfTransactional($version);
            } else {
                $this->removeVersion($version, $exception);
            }

            throw $exception;
        }

        if (! $this->deferIfTransactional($version)) {
            $this->cleanup();
        }

        return $version;
    }

    private function filesystem(string $version): FilesystemAdapter
    {
        return new VersionedFilesystem(
            $this->diskFilesystem,
            $this->directory ? $this->path.'/'.$version : $this->path,
        );
    }

    private function publish(string $version, bool $cleanup): void
    {
        $connection = $this->connection();

        $this->transaction(function () use ($connection, $version, $cleanup): void {
            $resource = $this->lockResource();

            if ($resource === null) {
                throw new RuntimeException("Unable to register versioned resource [{$this->path}].");
            }

            $connection->table('versioned_storage_versions')->insert([
                'resource_id' => $resource->id,
                'version' => $version,
                'created_at' => now(),
            ]);

            $connection->table('versioned_storage_resources')
                ->where('id', $resource->id)->update(['current_version' => $version]);

            if ($cleanup) {
                $this->resourceVersions($resource)->where('version', '!=', $version)->update(['pending_delete' => true]);
            }
        }, 5);
    }

    public function cleanup(): void
    {
        if ($this->connection()->transactionLevel() > 0) {
            app(VersionedStorageTransactions::class)->defer($this->connection(), $this);

            return;
        }

        $error = null;
        $pending = $this->versionQuery()->where('pending_delete', true)->pluck('version');

        foreach ($pending as $version) {
            try {
                $this->transaction(function () use ($version): void {
                    $resource = $this->lockResource();

                    if ($resource === null || $resource->current_version === $version) {
                        return;
                    }

                    $record = $this->resourceVersions($resource)->where('version', $version)
                        ->where('pending_delete', true)->lockForUpdate()->first();

                    if ($record === null) {
                        return;
                    }

                    $this->removeVersion($version);
                    $this->resourceVersions($resource)->where('version', $version)->delete();
                });
            } catch (Throwable $exception) {
                report($exception);
                $error ??= $exception;
            }
        }

        if ($error !== null) {
            throw $error;
        }
    }

    public function discard(string $version): void
    {
        $this->transaction(function () use ($version): void {
            if (! $this->versionQuery()->where('version', $version)->exists()) {
                $this->removeVersion($version);
            }
        });
    }

    private function deferIfTransactional(string $version): bool
    {
        if ($this->connection()->transactionLevel() === 0) {
            return false;
        }

        app(VersionedStorageTransactions::class)->defer($this->connection(), $this, $version);

        return true;
    }

    private function lockResource(): ?stdClass
    {
        $query = $this->connection()->table('versioned_storage_resources')->where('key', $this->key);

        // SQLite ignores FOR UPDATE, so acquire its write lock before reading a snapshot.
        if ($this->connection()->getDriverName() === 'sqlite') {
            $query->update(['key' => $this->key]);
        }

        return $query->lockForUpdate()->first();
    }

    private function resolveVersion(?string $version, ?stdClass $resource): ?string
    {
        if ($resource === null) {
            return null;
        }

        $version ??= $resource->current_version;

        return $version !== null && $this->resourceVersions($resource)->where('version', $version)
            ->where('pending_delete', false)->lockForUpdate()->exists() ? $version : null;
    }

    private function resourceVersions(stdClass $resource): Builder
    {
        return $this->connection()->table('versioned_storage_versions')->useWritePdo()->where('resource_id', $resource->id);
    }

    private function versionQuery(): Builder
    {
        return $this->connection()->table('versioned_storage_versions')->useWritePdo()
            ->whereIn('resource_id', $this->connection()->table('versioned_storage_resources')
                ->select('id')->where('key', $this->key));
    }

    private function connection(): Connection
    {
        return $this->database;
    }

    private function registerResource(): void
    {
        $connection = $this->connection();

        if ($connection->table('versioned_storage_resources')->useWritePdo()->where('key', $this->key)->exists()) {
            return;
        }

        $this->transaction(function () use ($connection): void {
            $this->lockResourceRegistration();
            $paths = $connection->table('versioned_storage_resources')->useWritePdo()
                ->where('key', '!=', $this->key)->lockForUpdate()->get(['disk', 'path']);

            foreach ($paths as $resource) {
                if (($this->path === $resource->path
                    || str_starts_with($this->path, $resource->path.'/')
                    || str_starts_with($resource->path, $this->path.'/'))
                    && $this->sharesFilesystem((string) $resource->disk)) {
                    throw new InvalidArgumentException(
                        "Versioned resource [{$this->path}] overlaps registered resource [{$resource->path}] on disk [{$this->disk}].",
                    );
                }
            }

            $connection->table('versioned_storage_resources')->insertOrIgnore([
                'key' => $this->key,
                'disk' => $this->disk,
                'path' => $this->path,
                'is_directory' => $this->directory,
            ]);
        }, 5);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  positive-int  $attempts
     * @return TReturn
     */
    private function transaction(Closure $callback, int $attempts = 1): mixed
    {
        $connection = $this->connection();
        $initialLevel = $connection->transactionLevel();

        try {
            return $connection->transaction($callback, $attempts);
        } catch (Throwable $exception) {
            try {
                // Beginning listeners can throw after the transaction or savepoint opens.
                if ($connection->transactionLevel() > $initialLevel) {
                    $connection->rollBack($initialLevel);
                }

                // Laravel can decrement its counter without rolling back a failed commit.
                if ($connection->transactionLevel() === 0 && $connection->getPdo()->inTransaction()) {
                    $connection->getPdo()->rollBack();
                    app(VersionedStorageTransactions::class)->rolledBack(new TransactionRolledBack($connection));
                }
            } catch (Throwable $recoveryException) {
                report($exception);

                throw $recoveryException;
            }

            throw $exception;
        }
    }

    private function removeVersion(string $version, ?Throwable $previous = null): void
    {
        if ($this->connection()->transactionLevel() > 0) {
            $this->lockResourceRegistration();
        }

        $filesystem = $this->diskFilesystem;
        $path = $this->path.'/'.$version;

        if ($this->directory) {
            $resources = $this->connection()->table('versioned_storage_resources')->useWritePdo()
                ->where('key', '!=', $this->key)->get(['disk', 'path']);

            foreach ($resources as $resource) {
                if (($resource->path === $path || str_starts_with($resource->path, $path.'/'))
                    && $this->sharesFilesystem((string) $resource->disk)) {
                    throw new RuntimeException("Cannot remove version [{$version}]: it contains registered resource [{$resource->path}].", 0, $previous);
                }
            }
        }

        $deleted = $this->directory ? $filesystem->deleteDirectory($path) : $filesystem->delete($path);

        if (! $deleted || $filesystem->exists($path)) {
            throw new RuntimeException("Unable to remove version [{$version}] of [{$this->path}].", 0, $previous);
        }
    }

    private function lockResourceRegistration(): void
    {
        $connection = $this->connection();
        $key = hash('sha256', 'versioned-storage-resource-registration');
        $connection->table('versioned_storage_disks')->insertOrIgnore(['key' => $key]);
        $lock = $connection->table('versioned_storage_disks')->where('key', $key);

        if ($connection->getDriverName() === 'sqlite') {
            $lock->update(['key' => $key]);
        }

        $lock->lockForUpdate()->first();
    }

    private function sharesFilesystem(string $disk): bool
    {
        try {
            return Storage::disk($disk)->getAdapter() === $this->diskFilesystem->getAdapter();
        } catch (InvalidArgumentException $exception) {
            // Resolve first so aliases registered with Storage::set() still participate.
            if (! empty(config("filesystems.disks.{$disk}.driver"))
                || $exception->getMessage() !== "Disk [{$disk}] does not have a configured driver.") {
                throw $exception;
            }

            return false;
        }
    }
}
