<?php

namespace SkyRaptor\LaravelVersionedStorage;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Filesystem\Filesystem;

class VersionedStorageHandler
{
    public function __construct(
        protected string $path,
        protected string $disk
    ) {}

    public function get(string $path, ?string $version = null): string
    {
        $version = VersionedStorage::directoryVersion($this->path, $version, $this->disk);

        if ($version === null) {
            throw new FileNotFoundException("No version exists for directory [{$this->path}].");
        }

        return VersionedStorage::scopedFilesystem($this->path, $version, $this->disk)->get($path);
    }

    public function exists(string $path, ?string $version = null): bool
    {
        $version = VersionedStorage::directoryVersion($this->path, $version, $this->disk);

        return $version !== null
            && VersionedStorage::scopedFilesystem($this->path, $version, $this->disk)->exists($path);
    }

    /**
     * @return list<string>
     */
    public function versions(): array
    {
        return VersionedStorage::directoryVersions($this->path, $this->disk);
    }

    public function filesystem(?string $version = null): ?Filesystem
    {
        $version = VersionedStorage::directoryVersion($this->path, $version, $this->disk);

        return $version === null
            ? null
            : VersionedStorage::scopedFilesystem($this->path, $version, $this->disk);
    }
}
