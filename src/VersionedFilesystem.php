<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;

class VersionedFilesystem extends FilesystemAdapter
{
    public function __construct(FilesystemAdapter $filesystem, string $prefix)
    {
        $adapter = new PathPrefixedAdapter($filesystem->getAdapter(), $prefix);
        $config = $filesystem->getConfig();
        $config['throw'] = true;
        $config['prefix'] = trim(($config['prefix'] ?? '').'/'.$prefix, '/');

        parent::__construct(new Filesystem($adapter, $config), $adapter, $config);
    }
}
