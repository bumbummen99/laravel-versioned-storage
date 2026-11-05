<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use DateTimeInterface;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\WhitespacePathNormalizer;

class ScopedFilesystemOperator implements FilesystemOperator
{
    private readonly PathPrefixer $prefixer;

    public function __construct(private readonly FilesystemOperator $filesystem, string $prefix)
    {
        $this->prefixer = new PathPrefixer($prefix);
    }

    public function fileExists(string $location): bool
    {
        return $this->filesystem->fileExists($this->path($location));
    }

    public function directoryExists(string $location): bool
    {
        return $this->filesystem->directoryExists($this->path($location));
    }

    public function has(string $location): bool
    {
        return $this->filesystem->has($this->path($location));
    }

    public function read(string $location): string
    {
        return $this->filesystem->read($this->path($location));
    }

    /** @return resource */
    public function readStream(string $location): mixed
    {
        return $this->filesystem->readStream($this->path($location));
    }

    /** @return DirectoryListing<StorageAttributes> */
    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        return $this->filesystem->listContents($this->path($location), $deep)
            ->map(fn (StorageAttributes $attributes): StorageAttributes => $attributes->withPath(
                $this->prefixer->stripPrefix($attributes->path()),
            ));
    }

    public function lastModified(string $path): int
    {
        return $this->filesystem->lastModified($this->path($path));
    }

    public function fileSize(string $path): int
    {
        return $this->filesystem->fileSize($this->path($path));
    }

    public function mimeType(string $path): string
    {
        return $this->filesystem->mimeType($this->path($path));
    }

    public function visibility(string $path): string
    {
        return $this->filesystem->visibility($this->path($path));
    }

    /** @param array<string, mixed> $config */
    public function write(string $location, string $contents, array $config = []): void
    {
        $this->filesystem->write($this->path($location), $contents, $config);
    }

    /**
     * @param  resource  $contents
     * @param  array<string, mixed>  $config
     */
    public function writeStream(string $location, mixed $contents, array $config = []): void
    {
        $this->filesystem->writeStream($this->path($location), $contents, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->filesystem->setVisibility($this->path($path), $visibility);
    }

    public function delete(string $location): void
    {
        $this->filesystem->delete($this->path($location));
    }

    public function deleteDirectory(string $location): void
    {
        $this->filesystem->deleteDirectory($this->path($location));
    }

    /** @param array<string, mixed> $config */
    public function createDirectory(string $location, array $config = []): void
    {
        $this->filesystem->createDirectory($this->path($location), $config);
    }

    /** @param array<string, mixed> $config */
    public function move(string $source, string $destination, array $config = []): void
    {
        $this->filesystem->move($this->path($source), $this->path($destination), $config);
    }

    /** @param array<string, mixed> $config */
    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->filesystem->copy($this->path($source), $this->path($destination), $config);
    }

    /** @param array<string, mixed> $config */
    public function checksum(string $path, array $config = []): string
    {
        return $this->filesystem->checksum($this->path($path), $config);
    }

    /** @param array<string, mixed> $config */
    public function publicUrl(string $path, array $config = []): string
    {
        return $this->filesystem->publicUrl($this->path($path), $config);
    }

    /** @param array<string, mixed> $config */
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
    {
        return $this->filesystem->temporaryUrl($this->path($path), $expiresAt, $config);
    }

    private function path(string $path): string
    {
        return $this->prefixer->prefixPath((new WhitespacePathNormalizer)->normalizePath($path));
    }
}
