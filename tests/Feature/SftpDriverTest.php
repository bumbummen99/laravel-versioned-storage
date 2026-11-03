<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\PhpseclibV3\ConnectionProvider;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToWriteFile;
use phpseclib3\Net\SFTP;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

beforeEach(function () {
    $this->remoteFiles = [];
    $this->remoteDirectories = [];
    $this->deletions = [];
    $this->failUploads = false;
    $this->failDeletions = false;
    $this->failVerification = false;
    $this->failAfterDeletion = false;
    $connection = Mockery::mock(SFTP::class);
    $connection->shouldReceive('is_file')->andReturnUsing(function (string $path): bool {
        if ($this->failVerification) {
            throw new RuntimeException('Connection lost during verification');
        }

        return isset($this->remoteFiles[$path]);
    });
    $connection->shouldReceive('is_dir')->andReturnUsing(fn (string $path): bool => isset($this->remoteDirectories[rtrim($path, '/')]));
    $connection->shouldReceive('mkdir')->andReturnUsing(function (string $path): bool {
        $this->remoteDirectories[rtrim($path, '/')] = true;

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

        if ($this->failDeletions) {
            return false;
        }

        foreach (array_keys($this->remoteFiles) as $file) {
            if ($file === $path || str_starts_with($file, rtrim($path, '/').'/')) {
                unset($this->remoteFiles[$file]);
            }
        }

        foreach (array_keys($this->remoteDirectories) as $directory) {
            if ($directory === rtrim($path, '/') || str_starts_with($directory, rtrim($path, '/').'/')) {
                unset($this->remoteDirectories[$directory]);
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

it('retains SFTP retry records if post-delete verification loses its connection', function () {
    $first = VersionedStorage::put('reports', 'original', disk: 'sftp-first');
    $this->failAfterDeletion = true;

    expect(fn () => VersionedStorage::put('reports', 'replacement', true, 'sftp-first'))
        ->toThrow(UnableToCheckFileExistence::class)
        ->and($this->remoteFiles)->not->toHaveKey("/remote/first/tenant/reports/{$first}")
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->value('pending_delete'))->toBe(1);

    $this->failAfterDeletion = false;
    $this->failVerification = false;
    VersionedStorage::cleanup('reports', 'sftp-first');

    expect(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeFalse()
        ->and(VersionedStorage::get('reports', disk: 'sftp-first'))->toBe('replacement');
});

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

it('retains retry metadata and reports SFTP cleanup refusals', function (bool $directory) {
    $write = fn (string $contents, bool $cleanup = false): string => $directory
        ? VersionedStorage::directory('reports', function (FilesystemAdapter $files) use ($contents): void {
            $files->put('summary.txt', $contents);
        }, $cleanup, 'sftp-first')
        : VersionedStorage::put('reports', $contents, $cleanup, 'sftp-first');
    $first = $write('original');
    $this->failDeletions = true;
    $error = null;

    try {
        $write('replacement', true);
    } catch (RuntimeException $exception) {
        $error = $exception;
    }

    $oldPath = "/remote/first/tenant/reports/{$first}".($directory ? '/summary.txt' : '');

    expect($this->remoteFiles[$oldPath])->toBe('original')
        ->and($directory
            ? VersionedStorage::directory('reports', disk: 'sftp-first')->get('summary.txt')
            : VersionedStorage::get('reports', disk: 'sftp-first'))->toBe('replacement')
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->value('pending_delete'))->toBe(1)
        ->and($error)->toBeInstanceOf(RuntimeException::class);

    $this->failDeletions = false;

    if ($directory) {
        VersionedStorage::directory('reports', disk: 'sftp-first')->cleanup();
    } else {
        VersionedStorage::cleanup('reports', 'sftp-first');
    }

    expect($this->remoteFiles)->not->toHaveKey($oldPath)
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeFalse();
})->with([false, true]);

it('verifies removal of empty SFTP snapshot directories', function () {
    $first = VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        $files->makeDirectory('empty');
    }, disk: 'sftp-first');
    $this->failDeletions = true;

    expect(fn () => VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        $files->put('summary.txt', 'replacement');
    }, cleanup: true, disk: 'sftp-first'))
        ->toThrow(RuntimeException::class, 'Unable to remove version')
        ->and($this->remoteFiles)->toHaveCount(1)
        ->and($this->remoteDirectories)->toHaveKey("/remote/first/tenant/reports/{$first}")
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->value('pending_delete'))->toBe(1);

    $this->failDeletions = false;
    VersionedStorage::directory('reports', disk: 'sftp-first')->cleanup();

    expect($this->remoteDirectories)->not->toHaveKey("/remote/first/tenant/reports/{$first}")
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeFalse()
        ->and(VersionedStorage::directory('reports', disk: 'sftp-first')->get('summary.txt'))->toBe('replacement');
});

it('finishes SFTP cleanup when the pending snapshot is already physically absent', function (bool $directory) {
    $version = $directory
        ? VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'original');
        }, disk: 'sftp-first')
        : VersionedStorage::put('reports', 'original', disk: 'sftp-first');
    DB::table('versioned_storage_versions')->where('version', $version)->update(['pending_delete' => true]);
    DB::table('versioned_storage_resources')->where('disk', 'sftp-first')->update(['current_version' => null]);
    $this->remoteFiles = [];
    $this->remoteDirectories = [];
    $this->failDeletions = true;

    if ($directory) {
        VersionedStorage::directory('reports', disk: 'sftp-first')->cleanup();
    } else {
        VersionedStorage::cleanup('reports', 'sftp-first');
    }

    expect(DB::table('versioned_storage_versions')->where('version', $version)->exists())->toBeFalse();
})->with([false, true]);
