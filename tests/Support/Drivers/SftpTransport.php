<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Tests\Support\Drivers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\PhpseclibV3\ConnectionProvider;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use Mockery;
use phpseclib3\Net\SFTP;
use RuntimeException;

final class SftpTransport
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, bool> */
    public array $directories = [];

    /** @var list<string> */
    public array $deletions = [];

    public bool $failUploads = false;

    public bool $failDeletions = false;

    public bool $failVerification = false;

    public bool $failAfterDeletion = false;

    public function connectDisks(): void
    {
        $connection = Mockery::mock(SFTP::class);
        $connection->shouldReceive('is_file')->andReturnUsing(function (string $path): bool {
            if ($this->failVerification) {
                throw new RuntimeException('Connection lost during verification');
            }

            return isset($this->files[$path]);
        });
        $connection->shouldReceive('is_dir')->andReturnUsing(fn (string $path): bool => isset($this->directories[rtrim($path, '/')]));
        $connection->shouldReceive('mkdir')->andReturnUsing(function (string $path): bool {
            $this->directories[rtrim($path, '/')] = true;

            return true;
        });
        $connection->shouldReceive('put')->andReturnUsing(function (string $path, mixed $contents, int $mode): bool {
            expect($mode)->toBe(SFTP::SOURCE_STRING);

            if ($this->failUploads) {
                return false;
            }

            $this->files[$path] = is_resource($contents) ? stream_get_contents($contents) : $contents;

            return true;
        });
        $connection->shouldReceive('get')->andReturnUsing(fn (string $path): string|false => $this->files[$path] ?? false);
        $connection->shouldReceive('delete')->andReturnUsing(function (string $path): bool {
            $this->deletions[] = $path;

            if ($this->failDeletions) {
                return false;
            }

            foreach (array_keys($this->files) as $file) {
                if ($file === $path || str_starts_with($file, rtrim($path, '/').'/')) {
                    unset($this->files[$file]);
                }
            }

            foreach (array_keys($this->directories) as $directory) {
                if ($directory === rtrim($path, '/') || str_starts_with($directory, rtrim($path, '/').'/')) {
                    unset($this->directories[$directory]);
                }
            }

            $this->failVerification = $this->failAfterDeletion;

            return true;
        });
        $connection->shouldReceive('rmdir')->andReturnUsing(fn (): bool => ! $this->failDeletions);
        $provider = Mockery::mock(ConnectionProvider::class);
        $provider->shouldReceive('provideConnection')->andReturn($connection);

        foreach (['sftp-first' => '/remote/first', 'sftp-second' => '/remote/second'] as $name => $root) {
            $adapter = new SftpAdapter($provider, $root);
            $config = ['driver' => 'sftp', 'root' => $root, 'prefix' => 'tenant', 'throw' => true];
            Storage::set($name, new FilesystemAdapter(
                new Filesystem(new PathPrefixedAdapter($adapter, 'tenant')),
                $adapter,
                $config,
            ));
        }
    }
}
