<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

it('rejects a supplied file that disappeared before it could be opened', function () {
    $path = tempnam(sys_get_temp_dir(), 'versioned-missing-upload-');
    $file = new File($path);
    unlink($path);
    $warnings = [];
    set_error_handler(function (int $severity, string $message) use (&$warnings): bool {
        if ($severity !== E_WARNING || ! str_starts_with($message, 'fopen(')) {
            return false;
        }
        $warnings[] = $message;

        return true;
    });

    try {
        expect(fn () => VersionedStorage::put('missing-upload', $file))
            ->toThrow(RuntimeException::class, 'Unable to open the supplied file for reading.');
        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain($path)
            ->and(VersionedStorage::versions('missing-upload'))->toBe([])
            ->and(DB::table('versioned_storage_operations')->count())->toBe(0)
            ->and(DB::table('versioned_storage_stages')->sole()->failed)->toBe(1);
        Storage::disk('local')->assertDirectoryEmpty('missing-upload');
    } finally {
        restore_error_handler();
    }
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
