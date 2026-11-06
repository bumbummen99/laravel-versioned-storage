<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;
use SkyRaptor\LaravelVersionedStorage\Filesystem\VersionedFilesystem;

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

it('passes the exact snapshot root to URL callbacks for empty paths and trailing prefixes', function (string $path) {
    $adapter = Mockery::mock(FlysystemAdapter::class);
    $original = new FilesystemAdapter(new Filesystem($adapter), $adapter);
    $original->buildTemporaryUrlsUsing(fn (string $path) => 'https://example.test/'.$path);
    $filesystem = new VersionedFilesystem($original, 'reports/version///');

    expect($filesystem->temporaryUrl($path, new DateTimeImmutable('+1 hour')))
        ->toBe('https://example.test/reports/version');
})->with(['', '/', './']);

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

it('prefixes a public URL once when the original disk already has its own prefix', function () {
    $adapter = Mockery::mock(FlysystemAdapter::class);
    $original = Mockery::mock(FilesystemAdapter::class, [new Filesystem($adapter), $adapter, ['prefix' => 'tenant']])
        ->makePartial();
    $original->shouldReceive('url')->once()->with('reports/version/summary.txt')
        ->andReturn('https://files.example.test/tenant/reports/version/summary.txt');
    $filesystem = new VersionedFilesystem($original, 'reports/version');

    expect($filesystem->url('summary.txt'))
        ->toBe('https://files.example.test/tenant/reports/version/summary.txt');
});

it('rejects URLs that escape the scoped directory', function (string $method) {
    $adapter = Mockery::mock(FlysystemAdapter::class);
    $original = Mockery::mock(FilesystemAdapter::class, [new Filesystem($adapter), $adapter])->makePartial();
    $original->shouldNotReceive($method);
    $filesystem = new VersionedFilesystem($original, 'reports/version');

    expect(fn () => $method === 'url'
        ? $filesystem->url('../escaped')
        : $filesystem->{$method}('../escaped', new DateTimeImmutable))
        ->toThrow(PathTraversalDetected::class);
})->with(['url', 'temporaryUrl']);

it('keeps temporary URL callbacks configured directly on the scoped filesystem working', function () {
    $adapter = Mockery::mock(FlysystemAdapter::class);
    $original = new FilesystemAdapter(new Filesystem($adapter), $adapter);
    $filesystem = new VersionedFilesystem($original, 'reports/version');
    $filesystem->buildTemporaryUrlsUsing(function (string $path): string {
        return 'https://custom.example.test/'.$path;
    });

    expect($filesystem->providesTemporaryUrls())->toBeTrue()
        ->and($filesystem->temporaryUrl('summary.txt', new DateTimeImmutable))
        ->toBe('https://custom.example.test/summary.txt');
});

it('preserves unsupported URL errors from the original disk', function (string $method) {
    $adapter = Mockery::mock(FlysystemAdapter::class);
    $original = new FilesystemAdapter(new Filesystem($adapter), $adapter);
    $filesystem = new VersionedFilesystem($original, 'reports/version');

    expect($filesystem->providesTemporaryUrls())->toBeFalse()
        ->and($filesystem->providesTemporaryUploadUrls())->toBeFalse();

    expect(fn () => $method === 'url'
        ? $filesystem->url('summary.txt')
        : $filesystem->{$method}('summary.txt', new DateTimeImmutable))
        ->toThrow(RuntimeException::class, match ($method) {
            'url' => 'This driver does not support retrieving URLs.',
            'temporaryUrl' => 'This driver does not support creating temporary URLs.',
        });
})->with(['url', 'temporaryUrl']);

it('rejects temporary upload URLs even with a custom scoped upload URL callback', function () {
    if (! method_exists(FilesystemAdapter::class, 'buildTemporaryUploadUrlsUsing')) {
        $this->markTestSkipped('This Laravel version does not support custom temporary upload URL callbacks.');
    }

    $adapter = Mockery::mock(FlysystemAdapter::class);
    $filesystem = new VersionedFilesystem(
        new FilesystemAdapter(new Filesystem($adapter), $adapter),
        'reports/version',
    );
    $filesystem->buildTemporaryUploadUrlsUsing(function (): never {
        throw new LogicException('The upload URL callback must not be invoked.');
    });

    expect($filesystem->providesTemporaryUploadUrls())->toBeFalse();
    expect(fn () => $filesystem->temporaryUploadUrl('summary.txt', new DateTimeImmutable))
        ->toThrow(RuntimeException::class, 'Temporary upload URLs are not supported for versioned snapshots.');
});
