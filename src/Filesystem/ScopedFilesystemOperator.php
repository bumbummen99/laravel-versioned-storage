<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Filesystem;

use Closure;
use DateTimeInterface;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\WhitespacePathNormalizer;
use SkyRaptor\LaravelVersionedStorage\Uploads\UploadOperation;

class ScopedFilesystemOperator implements FilesystemOperator
{
    private readonly PathPrefixer $prefixer;

    public function __construct(
        private readonly FilesystemOperator $filesystem,
        string $prefix,
        private readonly ?UploadOperation $operation = null,
    ) {
        $this->prefixer = new PathPrefixer($prefix);
    }

    public function fileExists(string $location): bool
    {
        return $this->call('fileExists', fn (FilesystemOperator $filesystem): bool => $filesystem->fileExists($this->path($location)));
    }

    public function directoryExists(string $location): bool
    {
        return $this->call('directoryExists', fn (FilesystemOperator $filesystem): bool => $filesystem->directoryExists($this->path($location)));
    }

    public function has(string $location): bool
    {
        return $this->call('has', fn (FilesystemOperator $filesystem): bool => $filesystem->has($this->path($location)));
    }

    public function read(string $location): string
    {
        return $this->call('read', fn (FilesystemOperator $filesystem): string => $filesystem->read($this->path($location)));
    }

    /** @return resource */
    public function readStream(string $location): mixed
    {
        $callback = fn (FilesystemOperator $filesystem) => $filesystem->readStream($this->path($location));

        return $this->operation === null
            ? $callback($this->filesystem)
            : $this->operation->readStream($callback, $this->filesystem);
    }

    /** @return DirectoryListing<StorageAttributes> */
    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        return $this->call('listContents', function (FilesystemOperator $filesystem) use ($location, $deep): DirectoryListing {
            $listing = $filesystem->listContents($this->path($location), $deep);

            return $this->operation === null ? $listing : new DirectoryListing($listing->toArray());
        })
            ->map(fn (StorageAttributes $attributes): StorageAttributes => $attributes->withPath(
                $this->prefixer->stripPrefix($attributes->path()),
            ));
    }

    public function lastModified(string $path): int
    {
        return $this->call('lastModified', fn (FilesystemOperator $filesystem): int => $filesystem->lastModified($this->path($path)));
    }

    public function fileSize(string $path): int
    {
        return $this->call('fileSize', fn (FilesystemOperator $filesystem): int => $filesystem->fileSize($this->path($path)));
    }

    public function mimeType(string $path): string
    {
        return $this->call('mimeType', fn (FilesystemOperator $filesystem): string => $filesystem->mimeType($this->path($path)));
    }

    public function visibility(string $path): string
    {
        return $this->call('visibility', fn (FilesystemOperator $filesystem): string => $filesystem->visibility($this->path($path)));
    }

    /** @param array<string, mixed> $config */
    public function write(string $location, string $contents, array $config = []): void
    {
        $this->call('write', fn (FilesystemOperator $filesystem) => $filesystem->write($this->path($location), $contents, $config));
    }

    /**
     * @param  resource  $contents
     * @param  array<string, mixed>  $config
     */
    public function writeStream(string $location, mixed $contents, array $config = []): void
    {
        $this->call('writeStream', fn (FilesystemOperator $filesystem) => $filesystem->writeStream($this->path($location), $contents, $config));
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->call('setVisibility', fn (FilesystemOperator $filesystem) => $filesystem->setVisibility($this->path($path), $visibility));
    }

    public function delete(string $location): void
    {
        $this->call('delete', fn (FilesystemOperator $filesystem) => $filesystem->delete($this->path($location)));
    }

    public function deleteDirectory(string $location): void
    {
        $this->call('deleteDirectory', fn (FilesystemOperator $filesystem) => $filesystem->deleteDirectory($this->path($location)));
    }

    /** @param array<string, mixed> $config */
    public function createDirectory(string $location, array $config = []): void
    {
        $this->call('createDirectory', fn (FilesystemOperator $filesystem) => $filesystem->createDirectory($this->path($location), $config));
    }

    /** @param array<string, mixed> $config */
    public function move(string $source, string $destination, array $config = []): void
    {
        $this->call('move', fn (FilesystemOperator $filesystem) => $filesystem->move($this->path($source), $this->path($destination), $config));
    }

    /** @param array<string, mixed> $config */
    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->call('copy', fn (FilesystemOperator $filesystem) => $filesystem->copy($this->path($source), $this->path($destination), $config));
    }

    /** @param array<string, mixed> $config */
    public function checksum(string $path, array $config = []): string
    {
        return $this->call('checksum', fn (FilesystemOperator $filesystem): string => $filesystem->checksum($this->path($path), $config));
    }

    /** @param array<string, mixed> $config */
    public function publicUrl(string $path, array $config = []): string
    {
        return $this->call('publicUrl', fn (FilesystemOperator $filesystem): string => $filesystem->publicUrl($this->path($path), $config));
    }

    /** @param array<string, mixed> $config */
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
    {
        return $this->call('temporaryUrl', fn (FilesystemOperator $filesystem): string => $filesystem->temporaryUrl($this->path($path), $expiresAt, $config));
    }

    /**
     * @template TResult
     *
     * @param  Closure(FilesystemOperator): TResult  $callback
     * @return TResult
     */
    private function call(string $operation, Closure $callback): mixed
    {
        return $this->operation === null
            ? $callback($this->filesystem)
            : $this->operation->run($operation, $callback, $this->filesystem);
    }

    private function path(string $path): string
    {
        return $this->prefixer->prefixPath((new WhitespacePathNormalizer)->normalizePath($path));
    }
}
