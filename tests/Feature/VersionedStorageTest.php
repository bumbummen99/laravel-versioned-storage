<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToWriteFile;
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

it('removes only previous file versions from the database and filesystem', function () {
    Storage::disk('local')->put('foo/bar/baz/unrelated', 'leave alone');
    $first = VersionedStorage::put('foo/bar/baz', 'first');
    $other = VersionedStorage::put('foo/bar/other', 'other');
    $second = VersionedStorage::put('foo/bar/baz', 'second', true);

    Storage::disk('local')->assertMissing("foo/bar/baz/{$first}");
    Storage::disk('local')->assertExists(["foo/bar/baz/{$second}", "foo/bar/other/{$other}", 'foo/bar/baz/unrelated']);
    expect(VersionedStorage::versions('foo/bar/baz'))->toBe([$second])
        ->and(VersionedStorage::exists('foo/bar/baz', $first))->toBeFalse()
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeFalse();
});

it('removes all files in previous directory versions without touching other data', function () {
    $first = VersionedStorage::directory('foo/bar', function (FilesystemAdapter $files): void {
        $files->put('one', 'one');
        $files->put('nested/two', 'two');
    });
    Storage::disk('local')->put('foo/bar/unrelated', 'leave alone');
    $second = VersionedStorage::directory('foo/bar', function (FilesystemAdapter $files): void {
        $files->put('three', 'three');
    }, true);

    Storage::disk('local')->assertDirectoryEmpty("foo/bar/{$first}");
    Storage::disk('local')->assertMissing(["foo/bar/{$first}/one", "foo/bar/{$first}/nested/two"]);
    Storage::disk('local')->assertExists(["foo/bar/{$second}/three", 'foo/bar/unrelated']);
    expect(VersionedStorage::directory('foo/bar')->versions())->toBe([$second]);
});

it('leaves the previous directory current and removes staged files when a callback fails', function () {
    $first = VersionedStorage::directory('foo/bar', function (FilesystemAdapter $files): void {
        $files->put('one', 'original');
    });

    expect(fn () => VersionedStorage::directory('foo/bar', function (FilesystemAdapter $files): void {
        $files->put('one', 'incomplete');

        throw new RuntimeException('callback failed');
    }, true))->toThrow(RuntimeException::class, 'callback failed');

    expect(VersionedStorage::directory('foo/bar')->versions())->toBe([$first])
        ->and(VersionedStorage::directory('foo/bar')->get('one'))->toBe('original')
        ->and(Storage::disk('local')->allFiles('foo/bar'))->toBe(["foo/bar/{$first}/one"]);
});

it('does not publish failed filesystem writes even on a non-throwing disk', function () {
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('write')->once()->andThrow(UnableToWriteFile::atLocation('file', 'write failed'));
    Storage::set('failing', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));

    expect(fn () => VersionedStorage::put('file', 'contents', disk: 'failing'))
        ->toThrow(UnableToWriteFile::class)
        ->and(VersionedStorage::versions('file', 'failing'))->toBe([])
        ->and(DB::table('versioned_storage_resources')->count())->toBe(0);
});

it('removes a staged version if its database publication fails', function () {
    $first = VersionedStorage::put('file', 'original');
    $connection = DB::connection();
    $connection->statement("CREATE TRIGGER reject_storage_version BEFORE INSERT ON versioned_storage_versions BEGIN SELECT RAISE(FAIL, 'publication failed'); END");

    try {
        expect(fn () => VersionedStorage::put('file', 'unpublished', true))
            ->toThrow(QueryException::class, 'publication failed');
    } finally {
        $connection->statement('DROP TRIGGER reject_storage_version');
    }

    expect(VersionedStorage::get('file'))->toBe('original')
        ->and(VersionedStorage::versions('file'))->toBe([$first])
        ->and(Storage::disk('local')->allFiles('file'))->toBe(["file/{$first}"]);
});

it('reports cleanup failures without deleting the newly published current version', function () {
    $first = VersionedStorage::put('file', 'original');
    $adapter = Mockery::mock(Storage::disk('local')->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with("file/{$first}")
        ->andThrow(UnableToDeleteFile::atLocation("file/{$first}"));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));

    expect(fn () => VersionedStorage::put('file', 'replacement', true))
        ->toThrow(RuntimeException::class, 'Unable to remove version')
        ->and(VersionedStorage::get('file'))->toBe('replacement')
        ->and(VersionedStorage::versions('file'))->toHaveCount(1)
        ->and(VersionedStorage::exists('file', $first))->toBeFalse();
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

it('keeps file and directory versions distinct even when their logical path is the same', function () {
    $file = VersionedStorage::put('shared', 'file');
    $directory = VersionedStorage::directory('shared', function (FilesystemAdapter $files): void {
        $files->put('child', 'directory');
    });

    VersionedStorage::put('shared', 'replacement', true);
    expect(VersionedStorage::exists('shared', $file))->toBeFalse()
        ->and(VersionedStorage::directory('shared')->get('child', $directory))->toBe('directory')
        ->and(VersionedStorage::directory('shared')->versions())->toBe([$directory]);
});

it('only cleans published versions when another writer is still preparing its snapshot', function () {
    $inner = null;
    $outer = VersionedStorage::directory('overlap', function (FilesystemAdapter $files) use (&$inner): void {
        $files->put('one', 'outer');
        $inner = VersionedStorage::directory('overlap', function (FilesystemAdapter $other): void {
            $other->put('one', 'inner');
        }, true);

        expect(VersionedStorage::directory('overlap')->get('one'))->toBe('inner');
        $files->put('two', 'complete');
    }, true);

    expect(VersionedStorage::directory('overlap')->get('one'))->toBe('outer')
        ->and(VersionedStorage::directory('overlap')->get('two'))->toBe('complete')
        ->and(VersionedStorage::directory('overlap')->versions())->toBe([$outer])
        ->and(VersionedStorage::directory('overlap')->exists('one', $inner))->toBeFalse();
});

it('uses the configured metadata connection for migrations and operations', function () {
    config()->set('database.connections.metadata', config('database.connections.testing'));
    config()->set('laravel-versioned-storage.connection', 'metadata');
    $migration = require __DIR__.'/../../database/migrations/2026_01_01_000000_create_versioned_storage_tables.php';
    $cleanupMigration = require __DIR__.'/../../database/migrations/2026_01_01_000001_add_pending_delete_to_versioned_storage_versions.php';
    $migration->up();
    $cleanupMigration->up();

    try {
        $version = VersionedStorage::put('connection', 'contents');
        expect(VersionedStorage::get('connection'))->toBe('contents')
            ->and(DB::connection('metadata')->table('versioned_storage_versions')->value('version'))->toBe($version)
            ->and(DB::connection('testing')->table('versioned_storage_versions')->count())->toBe(0);
    } finally {
        $cleanupMigration->down();
        $migration->down();
        DB::purge('metadata');
        config()->set('laravel-versioned-storage.connection', null);
    }
});

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
