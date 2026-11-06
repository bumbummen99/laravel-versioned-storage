<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToWriteFile;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('writes uploaded and local file contents to the exact version filename', function () {
    $upload = UploadedFile::fake()->createWithContent('example.txt', 'uploaded');
    $first = VersionedStorage::put('upload', $upload);
    $second = VersionedStorage::put('local-file', new File($upload->getPathname()));

    expect(Storage::disk('local')->fileExists("upload/{$first}"))->toBeTrue()
        ->and(VersionedStorage::get('upload'))->toBe('uploaded')
        ->and(Storage::disk('local')->fileExists("local-file/{$second}"))->toBeTrue()
        ->and(VersionedStorage::get('local-file'))->toBe('uploaded');
});

it('closes the supplied file stream after successful or failed writes', function (bool $failWrite) {
    $upload = UploadedFile::fake()->createWithContent('report.txt', 'contents');
    $disk = Storage::disk('local');
    $stream = null;
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('writeStream')->once()->andReturnUsing(
        function (string $path, mixed $resource, Config $config) use (&$stream, $disk, $failWrite): void {
            $stream = $resource;

            if ($failWrite) {
                throw UnableToWriteFile::atLocation($path, 'stream write refused');
            }

            $disk->getAdapter()->writeStream($path, $resource, $config);
        },
    );
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, $disk->getConfig()));

    try {
        if ($failWrite) {
            expect(fn () => VersionedStorage::put('stream-ownership', $upload))
                ->toThrow(UnableToWriteFile::class, 'stream write refused');
            expect(VersionedStorage::versions('stream-ownership'))->toBe([]);
        } else {
            VersionedStorage::put('stream-ownership', $upload);
            expect(VersionedStorage::get('stream-ownership'))->toBe('contents');
        }

        expect($stream)->not->toBeNull()
            ->and(is_resource($stream))->toBeFalse();
    } finally {
        Storage::set('local', $disk);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }
})->with(['successful write' => false, 'failed write' => true]);
