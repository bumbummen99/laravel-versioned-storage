<?php

declare(strict_types=1);

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PathTraversalDetected;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Adapters\ScopedFilesystemOperator;

afterEach(function (): void {
    Mockery::close();
});

it('delegates scoped filesystem operations and preserves their arguments and results', function (string $method, array $arguments, array $scopedArguments, mixed $result) {
    $operator = Mockery::mock(FilesystemOperator::class);
    $expectation = $operator->shouldReceive($method)->once()->withArgs($scopedArguments);
    $expectation->andReturn($result);
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->{$method}(...$arguments))->toBe($result);
})->with([
    'file existence' => ['fileExists', ['one'], ['reports/version/one'], true],
    'directory existence' => ['directoryExists', ['nested'], ['reports/version/nested'], true],
    'existence' => ['has', ['one'], ['reports/version/one'], true],
    'read' => ['read', ['one'], ['reports/version/one'], 'contents'],
    'last modified' => ['lastModified', ['one'], ['reports/version/one'], 123],
    'file size' => ['fileSize', ['one'], ['reports/version/one'], 8],
    'MIME type' => ['mimeType', ['one'], ['reports/version/one'], 'text/plain'],
    'visibility' => ['visibility', ['one'], ['reports/version/one'], 'private'],
    'write' => ['write', ['one', 'contents', ['visibility' => 'private']], ['reports/version/one', 'contents', ['visibility' => 'private']], null],
    'set visibility' => ['setVisibility', ['one', 'private'], ['reports/version/one', 'private'], null],
    'delete' => ['delete', ['one'], ['reports/version/one'], null],
    'delete directory' => ['deleteDirectory', ['nested'], ['reports/version/nested'], null],
    'create directory' => ['createDirectory', ['nested', ['directory_visibility' => 'private']], ['reports/version/nested', ['directory_visibility' => 'private']], null],
    'move' => ['move', ['one', 'two', ['retain_visibility' => false]], ['reports/version/one', 'reports/version/two', ['retain_visibility' => false]], null],
    'copy' => ['copy', ['one', 'two', ['visibility' => 'private']], ['reports/version/one', 'reports/version/two', ['visibility' => 'private']], null],
    'checksum' => ['checksum', ['one', ['checksum_algo' => 'sha256']], ['reports/version/one', ['checksum_algo' => 'sha256']], 'checksum'],
    'public URL' => ['publicUrl', ['one', []], ['reports/version/one', []], 'https://files.example.test/one'],
]);

it('keeps stream reads and writes on the original operator', function () {
    $stream = fopen('php://temp', 'w+b');
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('readStream')->once()->with('reports/version/one')->andReturn($stream);
    $operator->shouldReceive('writeStream')->once()->with('reports/version/two', $stream, ['visibility' => 'private']);
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    try {
        expect($scoped->readStream('one'))->toBe($stream);
        $scoped->writeStream('two', $stream, ['visibility' => 'private']);
    } finally {
        fclose($stream);
    }
});

it('returns directory listings relative to the snapshot', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('listContents')->once()->with('reports/version/', true)
        ->andReturn(new DirectoryListing([
            new DirectoryAttributes('reports/version/nested'),
            new FileAttributes('reports/version/nested/one', 8),
        ]));
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');
    $listing = $scoped->listContents('', true)->toArray();

    expect($listing[0]->path())->toBe('nested')
        ->and($listing[1]->path())->toBe('nested/one')
        ->and($listing[1]->fileSize())->toBe(8);
});

it('keeps an unsupervised listing lazy until the caller iterates it', function () {
    $visited = false;
    $contents = (function () use (&$visited): Generator {
        $visited = true;
        yield new FileAttributes('reports/version/one', 8);
    })();
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('listContents')->once()->andReturn(new DirectoryListing($contents));
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    $listing = $scoped->listContents('');
    expect($visited)->toBeFalse();
    expect($listing->toArray()[0]->path())->toBe('one');
    expect($visited)->toBeTrue();
});

it('delegates temporary URL generation with its expiration and options', function () {
    $expiration = new DateTimeImmutable;
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('temporaryUrl')->once()->with('reports/version/one', $expiration, ['download' => true])
        ->andReturn('https://files.example.test/signed');
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->temporaryUrl('one', $expiration, ['download' => true]))->toBe('https://files.example.test/signed');
});

it('rejects traversal in either copy path before invoking the original operator', function (string $source, string $destination) {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldNotReceive('copy');
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect(fn () => $scoped->copy($source, $destination))->toThrow(PathTraversalDetected::class);
})->with([['../outside', 'one'], ['one', '../outside']]);
