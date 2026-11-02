<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\WhitespacePathNormalizer;
use RuntimeException;

class VersionedFilesystem extends FilesystemAdapter
{
    public function __construct(
        private readonly FilesystemAdapter $filesystem,
        private readonly string $prefix,
    ) {
        $adapter = new PathPrefixedAdapter($filesystem->getAdapter(), $prefix);
        $config = $filesystem->getConfig();
        $config['throw'] = true;
        $config['prefix'] = trim(($config['prefix'] ?? '').'/'.$prefix, '/');

        parent::__construct(new Filesystem($adapter, $config), $adapter, $config);
    }

    /**
     * @param  string  $path
     */
    public function url($path): string
    {
        return $this->filesystem->url($this->scopedPath($path));
    }

    public function providesTemporaryUrls(): bool
    {
        return parent::providesTemporaryUrls() || $this->filesystem->providesTemporaryUrls();
    }

    /**
     * @param  string  $path
     * @param  DateTimeInterface  $expiration
     * @param  array<string, mixed>  $options
     */
    public function temporaryUrl($path, $expiration, array $options = []): string
    {
        return $this->temporaryUrlCallback
            ? parent::temporaryUrl($path, $expiration, $options)
            : $this->filesystem->temporaryUrl($this->scopedPath($path), $expiration, $options);
    }

    public function providesTemporaryUploadUrls(): bool
    {
        return false;
    }

    /**
     * @param  string  $path
     * @param  DateTimeInterface  $expiration
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function temporaryUploadUrl($path, $expiration, array $options = []): array
    {
        throw new RuntimeException('Temporary upload URLs are not supported for versioned snapshots.');
    }

    private function scopedPath(string $path): string
    {
        $relative = (new WhitespacePathNormalizer)->normalizePath($path);

        return rtrim($this->prefix, '/').($relative === '' ? '' : '/'.$relative);
    }
}
