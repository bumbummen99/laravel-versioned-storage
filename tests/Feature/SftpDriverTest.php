<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\PhpseclibV3\ConnectionProvider;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\UnableToWriteFile;
use phpseclib3\Net\SFTP;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

beforeEach(function () {
    $this->remoteFiles = [];
    $this->remoteDirectories = [];
    $this->deletions = [];
    $this->failUploads = false;
    $connection = Mockery::mock(SFTP::class);
    $connection->shouldReceive('is_file')->andReturnUsing(fn (string $path): bool => isset($this->remoteFiles[$path]));
    $connection->shouldReceive('is_dir')->andReturnUsing(fn (string $path): bool => isset($this->remoteDirectories[$path]));
    $connection->shouldReceive('mkdir')->andReturnUsing(function (string $path): bool {
        $this->remoteDirectories[$path] = true;

        return true;
    });
    $connection->shouldReceive('put')->andReturnUsing(function (string $path, mixed $contents, int $mode): bool {
        expect($mode)->toBe(SFTP::SOURCE_STRING);

        if ($this->failUploads) {
            return false;
        }

        $this->remoteFiles[$path] = is_resource($contents) ? stream_get_contents($contents) : $contents;

        return true;
    });
    $connection->shouldReceive('get')->andReturnUsing(fn (string $path): string|false => $this->remoteFiles[$path] ?? false);
    $connection->shouldReceive('delete')->andReturnUsing(function (string $path): bool {
        $this->deletions[] = $path;

        foreach (array_keys($this->remoteFiles) as $file) {
            if ($file === $path || str_starts_with($file, rtrim($path, '/').'/')) {
                unset($this->remoteFiles[$file]);
            }
            foreach (array_keys($this->remoteDirectories) as $directory) {
                if ($directory === rtrim($path, '/') || str_starts_with($directory, rtrim($path, '/').'/')) {
                    unset($this->remoteDirectories[$directory]);
                }
            }
        }

        return true;
    });
    $connection->shouldReceive('rmdir')->andReturn(true);
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
});

it('isolates SFTP snapshots by remote root including reads and cleanup', function (bool $directory) {
    $write = fn (string $disk, string $contents, bool $cleanup = false): string => $directory
        ? VersionedStorage::directory('reports', function (FilesystemAdapter $files) use ($contents): void {
            $files->put('nested/summary.txt', $contents);
        }, $cleanup, $disk)
        : VersionedStorage::put('reports', $contents, $cleanup, $disk);
    $first = $write('sftp-first', 'first');
    $other = $write('sftp-second', 'other');
    $replacement = $write('sftp-first', 'replacement', true);
    $suffix = $directory ? '/nested/summary.txt' : '';

    expect($directory
        ? VersionedStorage::directory('reports', disk: 'sftp-first')->get('nested/summary.txt')
        : VersionedStorage::get('reports', disk: 'sftp-first'))->toBe('replacement')
        ->and($directory
            ? VersionedStorage::directory('reports', disk: 'sftp-second')->get('nested/summary.txt')
            : VersionedStorage::get('reports', disk: 'sftp-second'))->toBe('other')
        ->and($this->remoteFiles)->toBe([
            "/remote/second/tenant/reports/{$other}{$suffix}" => 'other',
            "/remote/first/tenant/reports/{$replacement}{$suffix}" => 'replacement',
        ])
        ->and($this->deletions)->toContain("/remote/first/tenant/reports/{$first}".($directory ? '/' : ''));
})->with([false, true]);

it('removes rolled back SFTP snapshots from the selected root', function (bool $directory) {
    DB::beginTransaction();

    try {
        $version = $directory
            ? VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
                $files->put('summary.txt', 'staged');
            }, disk: 'sftp-first')
            : VersionedStorage::put('reports', 'staged', disk: 'sftp-first');
        expect($this->remoteFiles)->toHaveCount(1);
    } finally {
        DB::rollBack();
    }

    expect($this->remoteFiles)->toBe([])
        ->and($this->deletions)->toContain("/remote/first/tenant/reports/{$version}".($directory ? '/' : ''))
        ->and($directory
            ? VersionedStorage::directory('reports', disk: 'sftp-first')->versions()
            : VersionedStorage::versions('reports', 'sftp-first'))->toBe([]);
})->with([false, true]);

it('propagates SFTP upload errors without publishing a version', function () {
    $this->failUploads = true;

    expect(fn () => VersionedStorage::put('reports', 'denied', disk: 'sftp-first'))
        ->toThrow(UnableToWriteFile::class)
        ->and(VersionedStorage::versions('reports', 'sftp-first'))->toBe([])
        ->and($this->remoteFiles)->toBe([]);
});
