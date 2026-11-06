<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;
use SkyRaptor\LaravelVersionedStorage\Facades\VersionedStorage as VersionedStorageFacade;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('stores file versions at the documented paths and lists every version in order', function () {
    $first = VersionedStorage::put('/foo/bar/baz', 'first');
    $second = VersionedStorage::put('foo/bar/baz', 'second');

    Storage::disk('local')->assertExists(["foo/bar/baz/{$first}", "foo/bar/baz/{$second}"]);
    expect(Storage::disk('local')->get("foo/bar/baz/{$first}"))->toBe('first')
        ->and(Storage::disk('local')->get("foo/bar/baz/{$second}"))->toBe('second')
        ->and(VersionedStorage::versions('/foo/bar/baz'))->toBe([$first, $second])
        ->and(VersionedStorage::get('/foo/bar/baz'))->toBe('second')
        ->and(VersionedStorage::get('/foo/bar/baz', $first))->toBe('first');
});

it('stores independent directory snapshots and exposes scoped filesystem operations', function () {
    $first = VersionedStorage::directory('foo/bar', function (FilesystemAdapter $files): void {
        $files->put('nested/one.txt', 'first');
        $files->put('two.txt', 'two');
        expect($files->allFiles())->toBe(['nested/one.txt', 'two.txt']);
    });
    $handler = VersionedStorage::directory('foo/bar');
    $second = VersionedStorage::directory('foo/bar', function (FilesystemAdapter $files) use ($handler, $first): void {
        $files->put('nested/one.txt', 'second');
        expect($handler->get('nested/one.txt'))->toBe('first')
            ->and($handler->versions())->toBe([$first]);
    });

    Storage::disk('local')->assertExists(["foo/bar/{$first}/nested/one.txt", "foo/bar/{$second}/nested/one.txt"]);
    expect($handler->get('nested/one.txt'))->toBe('second')
        ->and($handler->get('nested/one.txt', $first))->toBe('first')
        ->and($handler->exists('two.txt'))->toBeFalse()
        ->and($handler->exists('two.txt', $first))->toBeTrue()
        ->and($handler->versions())->toBe([$first, $second]);
});

it('isolates versions by disk and honors the configured default disk', function () {
    Storage::fake('other');
    $local = VersionedStorage::put('file', 'local');
    $other = VersionedStorage::put('file', 'other', disk: 'other');

    config()->set('laravel-versioned-storage.disk', 'other');
    expect(VersionedStorage::get('file'))->toBe('other')
        ->and(VersionedStorage::versions('file'))->toBe([$other])
        ->and(VersionedStorage::get('file', disk: 'local'))->toBe('local')
        ->and(VersionedStorage::exists('file', $local))->toBeFalse();

    VersionedStorage::put('file', 'new', true);
    expect(VersionedStorage::get('file', disk: 'local'))->toBe('local');
});

it('rejects file and directory resources sharing the same root', function (bool $directoryFirst, bool $alias) {
    $disk = Storage::disk('local');
    Storage::set('local-alias', $disk);
    $called = false;
    $callback = function (FilesystemAdapter $files) use (&$called): void {
        $called = true;
        $files->put('child', 'directory');
    };

    try {
        $version = $directoryFirst
            ? VersionedStorage::directory('shared', $callback)
            : VersionedStorage::put('shared', 'file');
        $called = false;
        $targetDisk = $alias ? 'local-alias' : 'local';

        expect(fn () => $directoryFirst
            ? VersionedStorage::put('/shared/', 'replacement', disk: $targetDisk)
            : VersionedStorage::directory('/shared/', $callback, disk: $targetDisk))
            ->toThrow(InvalidArgumentException::class, 'overlaps registered resource');

        expect($called)->toBeFalse()
            ->and($directoryFirst
                ? VersionedStorage::versions('shared', $targetDisk)
                : VersionedStorage::directory('shared', disk: $targetDisk)->versions())->toBe([])
            ->and($directoryFirst
                ? VersionedStorage::directory('shared')->get('child', $version)
                : VersionedStorage::get('shared', $version))->toBe($directoryFirst ? 'directory' : 'file')
            ->and($disk->allFiles('shared'))->toBe([
                $directoryFirst ? "shared/{$version}/child" : "shared/{$version}",
            ]);
    } finally {
        Storage::forgetDisk('local-alias');
    }
})->with([true, false])->with([true, false]);

it('reports missing versions and files explicitly', function () {
    expect(VersionedStorage::exists('missing'))->toBeFalse()
        ->and(VersionedStorage::versions('missing'))->toBe([])
        ->and(VersionedStorage::directory('missing')->exists('file'))->toBeFalse()
        ->and(fn () => VersionedStorage::get('missing'))->toThrow(FileNotFoundException::class)
        ->and(fn () => VersionedStorage::directory('missing')->get('file'))->toThrow(FileNotFoundException::class);
});

it('rejects path traversal outside a directory version and removes incomplete writes', function () {
    expect(fn () => VersionedStorage::directory('foo', function (FilesystemAdapter $files): void {
        $files->put('../escaped', 'bad');
    }))->toThrow(PathTraversalDetected::class)
        ->and(VersionedStorage::directory('foo')->versions())->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('handles empty directory snapshots and empty file contents', function () {
    $version = VersionedStorage::directory('empty', function (FilesystemAdapter $files): void {});
    expect(Storage::disk('local')->directoryExists("empty/{$version}"))->toBeTrue()
        ->and(VersionedStorage::directory('empty')->versions())->toBe([$version]);

    VersionedStorage::put('empty-file', '');
    expect(VersionedStorage::get('empty-file'))->toBe('');
});

it('rejects empty resource paths', function () {
    expect(fn () => VersionedStorage::put('/', 'contents'))->toThrow(InvalidArgumentException::class);
});

it('supports the configured Laravel facade', function () {
    $version = VersionedStorageFacade::put('facade', 'contents');
    expect(VersionedStorageFacade::get('facade', $version))->toBe('contents');
});
