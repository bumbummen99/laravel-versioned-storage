<?php

namespace SkyRaptor\LaravelVersionedStorage;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;

class VersionedFilesystem implements Filesystem
{
    public function __construct(
        protected Filesystem $filesystem,
        protected string $prefix
    ) {
        $this->prefix = trim($this->prefix, '/');
    }

    public function path($path)
    {
        return $this->filesystem->path($this->prefixPath($path));
    }

    public function exists($path)
    {
        return $this->filesystem->exists($this->prefixPath($path));
    }

    public function get($path)
    {
        return $this->filesystem->get($this->prefixPath($path));
    }

    public function readStream($path)
    {
        return $this->filesystem->readStream($this->prefixPath($path));
    }

    public function put($path, $contents, $options = [])
    {
        return $this->filesystem->put($this->prefixPath($path), $contents, $options);
    }

    public function putFile($path, $file = null, $options = [])
    {
        return $this->filesystem->putFile($this->prefixPath($path), $file, $options);
    }

    public function putFileAs($path, $file, $name = null, $options = [])
    {
        return $this->filesystem->putFileAs($this->prefixPath($path), $file, $name, $options);
    }

    public function writeStream($path, $resource, array $options = [])
    {
        return $this->filesystem->writeStream($this->prefixPath($path), $resource, $options);
    }

    public function getVisibility($path)
    {
        return $this->filesystem->getVisibility($this->prefixPath($path));
    }

    public function setVisibility($path, $visibility)
    {
        return $this->filesystem->setVisibility($this->prefixPath($path), $visibility);
    }

    public function prepend($path, $data)
    {
        return $this->filesystem->prepend($this->prefixPath($path), $data);
    }

    public function append($path, $data)
    {
        return $this->filesystem->append($this->prefixPath($path), $data);
    }

    public function delete($paths)
    {
        return $this->filesystem->delete(
            is_array($paths)
                ? array_map(fn ($path) => $this->prefixPath($path), $paths)
                : $this->prefixPath($paths)
        );
    }

    public function copy($from, $to)
    {
        return $this->filesystem->copy($this->prefixPath($from), $this->prefixPath($to));
    }

    public function move($from, $to)
    {
        return $this->filesystem->move($this->prefixPath($from), $this->prefixPath($to));
    }

    public function size($path)
    {
        return $this->filesystem->size($this->prefixPath($path));
    }

    public function lastModified($path)
    {
        return $this->filesystem->lastModified($this->prefixPath($path));
    }

    public function files($directory = null, $recursive = false)
    {
        return $this->filesystem->files($this->prefixPath($directory ?? ''), $recursive);
    }

    public function allFiles($directory = null)
    {
        return $this->filesystem->allFiles($this->prefixPath($directory ?? ''));
    }

    public function directories($directory = null, $recursive = false)
    {
        return $this->filesystem->directories($this->prefixPath($directory ?? ''), $recursive);
    }

    public function allDirectories($directory = null)
    {
        return $this->filesystem->allDirectories($this->prefixPath($directory ?? ''));
    }

    public function makeDirectory($path)
    {
        return $this->filesystem->makeDirectory($this->prefixPath($path));
    }

    public function deleteDirectory($directory)
    {
        return $this->filesystem->deleteDirectory($this->prefixPath($directory));
    }

    private function prefixPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path, '/'));

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException('Directory paths cannot traverse above their version root.');
            }
        }

        return $this->prefix.($path === '' ? '' : '/'.$path);
    }
}
