<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use Closure;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\WhitespacePathNormalizer;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\Filesystem\VersionedFilesystem;
use SkyRaptor\LaravelVersionedStorage\Uploads\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Uploads\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Uploads\UploadDeadline;
use SkyRaptor\LaravelVersionedStorage\Uploads\UploadExecutionPolicy;
use SkyRaptor\LaravelVersionedStorage\Uploads\UploadJournal;
use SkyRaptor\LaravelVersionedStorage\Uploads\UploadOperation;
use stdClass;
use Throwable;

class VersionedStorageHandler
{
    private readonly string $path;

    private readonly string $key;

    private readonly Connection $database;

    private readonly UploadJournal $journal;

    private readonly FilesystemAdapter $diskFilesystem;

    public function __construct(
        string $path,
        private readonly string $disk,
        private readonly bool $directory = true,
        ?Connection $connection = null,
    ) {
        $this->path = (new WhitespacePathNormalizer)->normalizePath($path);

        if ($this->path === '') {
            throw new InvalidArgumentException('A versioned storage path must not be empty.');
        }

        $this->key = hash('sha256', $disk."\0".$this->path."\0".($directory ? 'directory' : 'file'));
        $this->database = $connection ?? DB::connection(config('laravel-versioned-storage.connection'));
        $this->journal = new UploadJournal($this->database, $this->key);
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
        return $this->stage($callback, true, $cleanup)->version;
    }

    /**
     * @param  Closure(FilesystemAdapter, string): mixed  $callback
     */
    public function stage(Closure $callback, bool $publish = false, bool $cleanup = false): StagedVersion
    {
        $this->assertOutsideTransaction();
        $execution = app(UploadExecutionPolicy::class)->execution();
        $grace = filter_var(config('laravel-versioned-storage.cleanup_grace'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($grace === false) {
            throw new RuntimeException('Cleanup grace must be a non-negative number of seconds.');
        }

        if ($execution['fork']) {
            $this->assertForkable();
        }
        $this->registerResource();
        $configuration = $this->uploadConfiguration();
        $version = $this->reserveDestination($execution, $configuration);
        $this->recordStage($version, $execution, $grace);
        $operation = $this->journal->operation($version, $execution, $configuration, $grace);

        try {
            $this->runSnapshotCallback($callback, $version, $operation);
            $this->completeStage($version, $publish, $cleanup);
        } catch (Throwable $exception) {
            $operation->close();
            $this->handleStageFailure($version, $exception);
        }

        return new StagedVersion($this, $version);
    }

    /** @return array<string, mixed> */
    private function uploadConfiguration(): array
    {
        $configuration = $this->diskFilesystem->getConfig();

        if (! isset($configuration['driver']) && $this->diskFilesystem->getAdapter() instanceof LocalFilesystemAdapter) {
            $configuration['driver'] = 'local';
        }

        return $configuration;
    }

    /**
     * @param  array{timeout: int, fork: bool}  $execution
     * @param  array<string, mixed>  $configuration
     */
    private function reserveDestination(array $execution, array $configuration): string
    {
        $version = (string) Str::uuid();
        $this->transaction(function () use ($version): void {
            $this->connection()->table('versioned_storage_reservations')->insert(['version' => $version]);
        });
        $destination = $this->path.'/'.$version;
        $deadline = new UploadDeadline($execution['timeout'], $execution['fork'], app(UploadClock::class));
        $exists = app(FilesystemOperationExecutor::class)->execute(
            static fn (FilesystemOperator $filesystem): bool => $filesystem->has($destination),
            $this->diskFilesystem->getDriver(),
            $configuration,
            $deadline,
            $execution['fork'],
        );

        if ($exists) {
            throw new RuntimeException("Version path [{$destination}] already exists.");
        }

        return $version;
    }

    /** @param array{timeout: int, fork: bool} $execution */
    private function recordStage(string $version, array $execution, int $grace): void
    {
        $cleanupAfter = (new UploadDeadline($execution['timeout'], $execution['fork'], app(UploadClock::class)))->recoveryAfter($grace);
        $this->transaction(function () use ($version, $cleanupAfter): void {
            $resource = $this->lockResource();

            if ($resource === null) {
                throw new RuntimeException("Unable to register versioned resource [{$this->path}].");
            }
            $this->connection()->table('versioned_storage_stages')->insert([
                'resource_id' => $resource->id, 'version' => $version, 'state' => 'uploading', 'cleanup_after' => $cleanupAfter,
            ]);
        });
    }

    /** @param Closure(FilesystemAdapter, string): mixed $callback */
    private function runSnapshotCallback(Closure $callback, string $version, UploadOperation $operation): void
    {
        $filesystem = new VersionedFilesystem(
            $this->diskFilesystem,
            $this->directory ? $this->path.'/'.$version : $this->path,
            $operation,
        );

        if ($this->directory) {
            $filesystem->makeDirectory('');
        }
        $callback($filesystem, $version);
        $operation->assertHealthy();
        $this->assertOutsideTransaction();
        $operation->close();
    }

    private function completeStage(string $version, bool $publish, bool $cleanup): void
    {
        $this->transaction(function () use ($version, $publish, $cleanup): void {
            $stage = $this->journal->lock($version);

            if ($stage === null || $stage->state !== 'uploading' || $stage->failed
                || $this->connection()->table('versioned_storage_operations')->where('version', $version)->exists()) {
                throw new RuntimeException("Stage [{$version}] failed or was reclaimed before upload completion.");
            }
            $this->journal->query($version)->update(['state' => 'ready']);

            if ($publish) {
                $this->publishStage($version, $cleanup);
            }
        });
    }

    private function handleStageFailure(string $version, Throwable $exception): never
    {
        if (! $this->versionQuery()->where('version', $version)->exists()) {
            try {
                $this->transaction(function () use ($version, $exception): void {
                    $stage = $this->journal->lock($version);

                    if ($stage !== null && $stage->state === 'uploading') {
                        $this->journal->query($version)->update(['failed' => true]);

                        if (! $this->connection()->table('versioned_storage_operations')->where('version', $version)->exists()) {
                            $this->removeVersion($version, $exception);
                        }
                    }
                });
            } catch (Throwable $deletionException) {
                report($exception);

                throw $deletionException;
            }
        }

        throw $exception;
    }

    private function assertForkable(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            throw new RuntimeException('CLI and development uploads require PCNTL and POSIX for supervised forks.');
        }
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() > 0 || MetadataTransaction::hasPdoTransaction($connection)) {
                throw new RuntimeException('Forked uploads cannot inherit active database transactions. Stage first.');
            }
        }
    }

    private function assertOutsideTransaction(): void
    {
        MetadataTransaction::assertOutsideTransaction($this->connection());
    }

    private function filesystem(string $version): FilesystemAdapter
    {
        return new VersionedFilesystem(
            $this->diskFilesystem,
            $this->directory ? $this->path.'/'.$version : $this->path,
        );
    }

    public function publishStage(string $version, bool $cleanup = false): void
    {
        $connection = $this->connection();

        if ($connection->transactionLevel() === 0 && MetadataTransaction::hasPdoTransaction($connection)) {
            throw new RuntimeException('Publish stages using Laravel-managed metadata transactions, not raw PDO transactions.');
        }

        $this->transaction(function () use ($connection, $version, $cleanup): void {
            $stage = $this->journal->lock($version);

            if ($stage === null) {
                throw new RuntimeException("Stage [{$version}] does not exist or was discarded.");
            }

            if ($stage->state === 'published') {
                return;
            }

            if ($stage->state !== 'ready') {
                throw new RuntimeException("Stage [{$version}] is not ready for publication.");
            }
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
            $this->journal->query($version)->update(['state' => 'published']);
        }, 5);
    }

    public function cleanup(): void
    {
        if ($this->connection()->transactionLevel() > 0 || MetadataTransaction::hasPdoTransaction($this->connection())) {
            throw new RuntimeException('Version cleanup cannot run inside an active metadata transaction.');
        }

        $error = null;
        $this->recoverStages();
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

    public function discardStage(string $version): void
    {
        $this->assertOutsideTransaction();
        $this->transaction(function () use ($version): void {
            $stage = $this->journal->lock($version);

            if ($stage === null) {
                return;
            }

            if ($stage->state === 'published') {
                throw new RuntimeException("Published stage [{$version}] cannot be discarded.");
            }

            if ($stage->state === 'uploading' && $stage->cleanup_after > now()->toDateTimeString()) {
                throw new RuntimeException("Incomplete stage [{$version}] must retain its journal until the cleanup deadline.");
            }
            $this->journal->query($version)->update(['state' => 'discarding', 'cleanup_after' => now()]);
        });
        $this->transaction(function () use ($version): void {
            $stage = $this->journal->lock($version);

            if ($stage === null) {
                return;
            }

            if ($stage->state !== 'discarding') {
                throw new RuntimeException("Stage [{$version}] is not available for deletion.");
            }
            $this->removeVersion($version);
            $this->connection()->table('versioned_storage_operations')->where('version', $version)->delete();
            $this->journal->query($version)->delete();
        });
    }

    public function recoverStages(): void
    {
        $this->assertOutsideTransaction();
        $candidates = $this->connection()->table('versioned_storage_stages')->useWritePdo()
            ->whereIn('resource_id', $this->connection()->table('versioned_storage_resources')->select('id')->where('key', $this->key))
            ->whereIn('state', ['uploading', 'discarding'])
            ->where('cleanup_after', '<=', now())
            ->where('id', '<=', $this->connection()->table('versioned_storage_stages')->useWritePdo()->max('id') ?? 0);

        $candidates->chunkById(100, function (Collection $stages): void {
            foreach ($stages as $candidate) {
                $version = $candidate->version;
                $this->transaction(function () use ($version): void {
                    $stage = $this->journal->lock($version, true);

                    if ($stage === null || ! in_array($stage->state, ['uploading', 'discarding'], true)
                        || $stage->cleanup_after > now()->toDateTimeString()) {
                        return;
                    }

                    if ($this->connection()->table('versioned_storage_operations')->where('version', $version)
                        ->where('cleanup_after', '>', now())->exists()) {
                        return;
                    }
                    $this->retainDiscard($version);
                    $this->connection()->table('versioned_storage_operations')->where('version', $version)->delete();
                    $this->journal->query($version)->delete();
                });
            }
        });
    }

    public function retainDiscard(string $version): void
    {
        $this->transaction(function () use ($version): void {
            $this->registerResource();
            $resource = $this->lockResource();

            if ($resource === null) {
                throw new RuntimeException("Unable to register rollback cleanup for [{$this->path}].");
            }

            if ($this->versionQuery()->where('version', $version)->exists()) {
                return;
            }

            $this->connection()->table('versioned_storage_reservations')->insertOrIgnore(['version' => $version]);
            $this->connection()->table('versioned_storage_versions')->insert([
                'resource_id' => $resource->id,
                'version' => $version,
                'created_at' => now(),
                'pending_delete' => true,
            ]);
        });
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
        return MetadataTransaction::run($this->connection(), $callback, $attempts);
    }

    private function removeVersion(string $version, ?Throwable $previous = null): void
    {
        if ($this->connection()->transactionLevel() > 0) {
            $this->lockResourceRegistration();
        }

        $this->assertRemovable($version, $previous);
        $path = $this->path.'/'.$version;

        SnapshotDeletion::delete($this->diskFilesystem, $path, $this->directory, $previous);
    }

    /**
     * @return Closure(): array{version: string, error: ?string}
     */
    public function deletionTask(string $version): Closure
    {
        $this->assertRemovable($version);

        return SnapshotDeletion::task(
            $this->disk,
            $this->path.'/'.$version,
            $version,
            $this->directory,
            hash('sha256', json_encode($this->diskFilesystem->getConfig(), JSON_THROW_ON_ERROR)),
        );
    }

    private function assertRemovable(string $version, ?Throwable $previous = null): void
    {
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
    }

    private function lockResourceRegistration(): void
    {
        $connection = $this->connection();
        $key = hash('sha256', 'versioned-storage-resource-registration');
        $connection->table('versioned_storage_locks')->insertOrIgnore(['key' => $key]);
        $lock = $connection->table('versioned_storage_locks')->where('key', $key);

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
