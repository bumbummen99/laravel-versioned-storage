<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToDeleteFile;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('removes only previous file versions from the database and filesystem', function () {
    Storage::disk('local')->put('foo/bar/baz/unrelated', 'leave alone');
    $first = VersionedStorage::put('foo/bar/baz', 'first');
    $other = VersionedStorage::put('foo/bar/other', 'other');
    $second = VersionedStorage::put('foo/bar/baz', 'second', true);
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

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
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    Storage::disk('local')->assertDirectoryEmpty("foo/bar/{$first}");
    Storage::disk('local')->assertMissing(["foo/bar/{$first}/one", "foo/bar/{$first}/nested/two"]);
    Storage::disk('local')->assertExists(["foo/bar/{$second}/three", 'foo/bar/unrelated']);
    expect(VersionedStorage::directory('foo/bar')->versions())->toBe([$second]);
});

it('can retry staged snapshot deletion after both a directory callback and its cleanup fail', function () {
    $first = VersionedStorage::directory('callback-retry', function (FilesystemAdapter $files): void {
        $files->put('one', 'original');
    });
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('deleteDirectory')->once()
        ->andThrow(new RuntimeException('snapshot deletion refused'));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, $disk->getConfig()));

    try {
        expect(fn () => VersionedStorage::directory('callback-retry', function (FilesystemAdapter $files): void {
            $files->put('one', 'incomplete');

            throw new RuntimeException('callback failed');
        }, cleanup: true))->toThrow(RuntimeException::class, 'snapshot deletion refused');
    } finally {
        Storage::set('local', $disk);
    }

    $staged = array_values(array_diff($disk->directories('callback-retry'), ["callback-retry/{$first}"]));
    expect($staged)->toHaveCount(1)
        ->and(VersionedStorage::directory('callback-retry')->versions())->toBe([$first])
        ->and(VersionedStorage::directory('callback-retry')->get('one'))->toBe('original')
        ->and($disk->get($staged[0].'/one'))->toBe('incomplete');

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    $disk->assertExists($staged[0].'/one');
    $stage = DB::table('versioned_storage_stages')->where('version', basename($staged[0]))->first();
    expect($stage->state)->toBe('uploading');
    $this->travelTo(Carbon::parse($stage->cleanup_after));
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    $disk->assertMissing($staged[0]);
    $disk->assertExists("callback-retry/{$first}/one");
    expect(VersionedStorage::directory('callback-retry')->get('one'))->toBe('original');
});

it('can retry staged snapshot deletion after publication and its cleanup fail', function (bool $directory, bool $newResource) {
    $write = static fn (string $contents): string => $directory
        ? VersionedStorage::directory('publication-retry', function (FilesystemAdapter $files) use ($contents): void {
            $files->put('one', $contents);
        }, cleanup: true)
        : VersionedStorage::put('publication-retry', $contents, cleanup: true);
    $first = $newResource ? null : $write('original');
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive($directory ? 'deleteDirectory' : 'delete')->once()
        ->andThrow(new RuntimeException('snapshot deletion refused'));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, $disk->getConfig()));
    $connection = DB::connection();
    $connection->statement("CREATE TRIGGER reject_storage_version BEFORE INSERT ON versioned_storage_versions WHEN NEW.pending_delete = 0 BEGIN SELECT RAISE(FAIL, 'publication failed'); END");

    try {
        expect(fn () => $write('unpublished'))->toThrow(RuntimeException::class, 'snapshot deletion refused');
    } finally {
        Storage::set('local', $disk);

        $connection->statement('DROP TRIGGER reject_storage_version');
    }

    $expected = $first === null ? [] : [$first];
    expect($directory
        ? VersionedStorage::directory('publication-retry')->versions()
        : VersionedStorage::versions('publication-retry'))->toBe($expected);
    $pending = DB::table('versioned_storage_stages')->where('state', 'uploading')->pluck('version')->all();
    expect($pending)->toHaveCount(1)
        ->and(DB::table('versioned_storage_reservations')->where('version', $pending[0])->exists())->toBeTrue();
    $disk->assertExists('publication-retry/'.$pending[0]);

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    $disk->assertExists('publication-retry/'.$pending[0]);
    expect(DB::table('versioned_storage_stages')->where('version', $pending[0])->value('state'))->toBe('uploading');
    $this->travelTo(Carbon::parse(DB::table('versioned_storage_stages')->where('version', $pending[0])->value('cleanup_after')));
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    $disk->assertMissing('publication-retry/'.$pending[0]);
    expect(DB::table('versioned_storage_stages')->where('version', $pending[0])->exists())->toBeFalse();
    expect(DB::table('versioned_storage_versions')->where('pending_delete', true)->exists())->toBeFalse();

    if ($first !== null) {
        expect($directory
            ? VersionedStorage::directory('publication-retry')->get('one')
            : VersionedStorage::get('publication-retry'))->toBe('original');
    }
})->with([false, true])->with([false, true]);

it('reports cleanup failures without deleting the newly published current version', function () {
    $first = VersionedStorage::put('file', 'original');
    $adapter = Mockery::mock(Storage::disk('local')->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with("file/{$first}")
        ->andThrow(UnableToDeleteFile::atLocation("file/{$first}"));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));

    VersionedStorage::put('file', 'replacement', true);
    expect(fn () => VersionedStorage::cleanup('file'))
        ->toThrow(RuntimeException::class, 'Unable to remove version')
        ->and(VersionedStorage::get('file'))->toBe('replacement')
        ->and(VersionedStorage::versions('file'))->toHaveCount(1)
        ->and(VersionedStorage::exists('file', $first))->toBeFalse();
});

it('preserves completed stages while retiring previously published snapshots', function () {
    $staged = VersionedStorage::stageDirectory('overlap', function (FilesystemAdapter $files): void {
        $files->put('one', 'outer');
        $files->put('two', 'complete');
    });
    $inner = VersionedStorage::directory('overlap', function (FilesystemAdapter $other): void {
        $other->put('one', 'inner');
    }, true);
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    $outer = $staged->publish(cleanup: true);

    expect(VersionedStorage::directory('overlap')->get('one'))->toBe('outer')
        ->and(VersionedStorage::directory('overlap')->get('two'))->toBe('complete')
        ->and(VersionedStorage::directory('overlap')->versions())->toBe([$outer])
        ->and(VersionedStorage::directory('overlap')->exists('one', $inner))->toBeFalse();
});
