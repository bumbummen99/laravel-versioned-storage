<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\Ftp\FtpConnectionProvider;
use League\Flysystem\Ftp\UnableToConnectToFtpHost;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('routes FTP snapshot operations through the selected adapter and prefix', function (bool $directory) {
    // FTP uses native PHP functions; successful operations are mocked at the adapter boundary.
    $adapter = Mockery::mock(FtpAdapter::class);
    $config = ['driver' => 'ftp', 'root' => '/remote', 'prefix' => 'tenant', 'throw' => true];
    Storage::set('ftp', new FilesystemAdapter(
        new Filesystem(new PathPrefixedAdapter($adapter, 'tenant')),
        $adapter,
        $config,
    ));
    $other = Mockery::mock(FtpAdapter::class);
    Storage::set('other-ftp', new FilesystemAdapter(new Filesystem($other), $other));
    $snapshot = (object) ['path' => null];
    $adapter->shouldReceive('fileExists')->twice()->with(Mockery::on(function (string $path) use ($snapshot): bool {
        $snapshot->path = $path;

        return str_starts_with($path, 'tenant/reports/');
    }))->andReturn(false);
    $adapter->shouldReceive('directoryExists')->twice()->with(Mockery::on(
        fn (string $path): bool => $path === $snapshot->path,
    ))->andReturn(false);

    if ($directory) {
        $adapter->shouldReceive('createDirectory')->once()->with(
            Mockery::on(fn (string $path): bool => rtrim($path, '/') === $snapshot->path),
            Mockery::type(Config::class),
        );
    }

    $adapter->shouldReceive('write')->once()->with(
        Mockery::on(fn (string $path): bool => $path === $snapshot->path.($directory ? '/nested/summary.txt' : '')),
        'contents',
        Mockery::type(Config::class),
    );
    $adapter->shouldReceive('read')->once()->with(
        Mockery::on(fn (string $path): bool => $path === $snapshot->path.($directory ? '/nested/summary.txt' : '')),
    )->andReturn('contents');
    $adapter->shouldReceive($directory ? 'deleteDirectory' : 'delete')->once()->with(
        Mockery::on(fn (string $path): bool => rtrim($path, '/') === $snapshot->path),
    );

    $stage = $directory
        ? VersionedStorage::stageDirectory('reports', function (FilesystemAdapter $files): void {
            $files->put('nested/summary.txt', 'contents');
        }, disk: 'ftp')
        : VersionedStorage::stage('reports', 'contents', disk: 'ftp');

    DB::beginTransaction();

    try {
        $version = $stage->publish();

        expect($snapshot->path)->toBe("tenant/reports/{$version}")
            ->and($directory
                ? VersionedStorage::directory('reports', disk: 'ftp')->get('nested/summary.txt')
                : VersionedStorage::get('reports', disk: 'ftp'))->toBe('contents');
    } finally {
        DB::rollBack();
    }

    expect($directory
        ? VersionedStorage::directory('reports', disk: 'ftp')->versions()
        : VersionedStorage::versions('reports', 'ftp'))->toBe([]);
    $stage->discard();
})->with([false, true]);

it('propagates real FTP adapter connection failures without publishing a version', function () {
    $provider = Mockery::mock(FtpConnectionProvider::class);
    $provider->shouldReceive('createConnection')->atLeast()->once()
        ->andThrow(UnableToConnectToFtpHost::forHost('ftp.example.test', 21, false, 'Connection denied'));
    $adapter = new FtpAdapter(FtpConnectionOptions::fromArray([
        'host' => 'ftp.example.test',
        'root' => '/remote',
        'username' => 'testing',
        'password' => 'testing',
    ]), $provider);
    Storage::set('ftp', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => true]));

    expect(fn () => VersionedStorage::put('reports', 'contents', disk: 'ftp'))
        ->toThrow(UnableToConnectToFtpHost::class)
        ->and(VersionedStorage::versions('reports', 'ftp'))->toBe([]);
});
