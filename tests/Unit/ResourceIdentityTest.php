<?php

declare(strict_types=1);

use League\Flysystem\PathTraversalDetected;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

it('normalizes a resource path before storing its identity', function () {
    $resource = new ResourceIdentity('/reports/./monthly/', 'local', true);

    expect($resource->path)->toBe('reports/monthly')
        ->and($resource->disk)->toBe('local')
        ->and($resource->directory)->toBeTrue();
});

it('gives equivalent normalized paths the same identity', function () {
    $normalized = new ResourceIdentity('reports/monthly', 'local', false);
    $withSlashes = new ResourceIdentity('/reports/./monthly/', 'local', false);

    expect($withSlashes->key)->toBe($normalized->key);
});

it('distinguishes resources on different disks', function () {
    $local = new ResourceIdentity('reports', 'local', false);
    $archive = new ResourceIdentity('reports', 'archive', false);

    expect($archive->key)->not->toBe($local->key);
});

it('distinguishes file and directory resources at the same path', function () {
    $file = new ResourceIdentity('reports', 'local', false);
    $directory = new ResourceIdentity('reports', 'local', true);

    expect($directory->key)->not->toBe($file->key);
});

it('distinguishes paths with similar names', function () {
    $reports = new ResourceIdentity('reports', 'local', false);
    $archive = new ResourceIdentity('reports-archive', 'local', false);

    expect($archive->key)->not->toBe($reports->key);
});

it('rejects paths that normalize to an empty resource address', function (string $path) {
    expect(fn () => new ResourceIdentity($path, 'local', false))
        ->toThrow(InvalidArgumentException::class, 'A versioned storage path must not be empty.');
})->with([
    'empty path' => '',
    'root slash' => '/',
    'current directory' => '.',
]);

it('rejects paths escaping the filesystem root', function () {
    expect(fn () => new ResourceIdentity('../reports', 'local', false))
        ->toThrow(PathTraversalDetected::class);
});
