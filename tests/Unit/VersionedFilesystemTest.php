<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;
use SkyRaptor\LaravelVersionedStorage\VersionedFilesystem;

afterEach(function (): void {
    Mockery::close();
});

it('does not require a booted Laravel application', function () {
    expect($this)->toBeInstanceOf(TestCase::class)
        ->not->toBeInstanceOf(Orchestra\Testbench\TestCase::class);
});

it('prefixes filesystem writes without accessing the application container', function () {
    $adapter = Mockery::mock(FlysystemAdapter::class);
    $adapter->shouldReceive('write')->once()->with(
        'reports/version/summary.txt',
        'contents',
        Mockery::type(Config::class),
    );
    $filesystem = new VersionedFilesystem(
        new FilesystemAdapter(new Filesystem($adapter), $adapter),
        'reports/version',
    );

    expect($filesystem->put('summary.txt', 'contents'))->toBeTrue();
});

it('prefixes reads and returns their contents', function () {
    $adapter = Mockery::mock(FlysystemAdapter::class);
    $adapter->shouldReceive('read')->once()->with('reports/version/summary.txt')->andReturn('contents');
    $filesystem = new VersionedFilesystem(
        new FilesystemAdapter(new Filesystem($adapter), $adapter),
        'reports/version',
    );

    expect($filesystem->get('summary.txt'))->toBe('contents');
});

it('rejects traversal before calling the underlying adapter', function () {
    $adapter = Mockery::mock(FlysystemAdapter::class);
    $adapter->shouldNotReceive('write');
    $filesystem = new VersionedFilesystem(
        new FilesystemAdapter(new Filesystem($adapter), $adapter),
        'reports/version',
    );

    expect(fn () => $filesystem->put('../escaped', 'contents'))->toThrow(PathTraversalDetected::class);
});

it('throws write errors even if the original adapter is configured not to throw', function () {
    $adapter = Mockery::mock(FlysystemAdapter::class);
    $adapter->shouldReceive('write')->once()
        ->andThrow(UnableToWriteFile::atLocation('reports/version/summary.txt'));
    $filesystem = new VersionedFilesystem(
        new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]),
        'reports/version',
    );

    expect(fn () => $filesystem->put('summary.txt', 'contents'))->toThrow(UnableToWriteFile::class);
});
