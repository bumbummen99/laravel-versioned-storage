<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use InvalidArgumentException;
use League\Flysystem\WhitespacePathNormalizer;

/**
 * Identifies one logical file or directory by its named disk, normalized path, and kind.
 * "Resource" is the metadata name for that address, not an additional application service.
 * Immutable and shared for a handler's lifetime.
 * Contains no connections, adapters, cached records, or per-upload state.
 */
final readonly class ResourceIdentity
{
    public string $path;

    public string $key;

    public function __construct(string $path, public string $disk, public bool $directory)
    {
        $this->path = (new WhitespacePathNormalizer)->normalizePath($path);

        if ($this->path === '') {
            throw new InvalidArgumentException('A versioned storage path must not be empty.');
        }

        $this->key = hash('sha256', $disk."\0".$this->path."\0".($directory ? 'directory' : 'file'));
    }
}
