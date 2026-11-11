<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use SkyRaptor\LaravelVersionedStorage\Testing\VersionedStorageTesting;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

beforeEach(function () {
    VersionedStorageTesting::restoreUploadExecution();
});

it('keeps callbacks and every metadata query in the parent when supervising filesystem operations', function () {
    $parent = getmypid();
    DB::listen(function (QueryExecuted $event) use ($parent): void {
        if (getmypid() !== $parent) {
            throw new RuntimeException('Child attempted a metadata query: '.$event->sql);
        }
    });
    $called = false;
    $stage = VersionedStorage::stageDirectory('reports', function (FilesystemAdapter $files) use ($parent, &$called): void {
        expect(getmypid())->toBe($parent)->and(DB::transactionLevel())->toBe(0);
        $called = true;
        $files->put('one', 'contents');
        $stream = $files->readStream('one');
        expect(stream_get_contents($stream))->toBe('contents');
        fclose($stream);
        expect($files->allFiles())->toBe(['one']);
    });

    expect($called)->toBeTrue()
        ->and(DB::table('versioned_storage_operations')->count())->toBe(0)
        ->and($stage->publish())->toBe($stage->version)
        ->and(VersionedStorage::directory('reports')->get('one'))->toBe('contents');
});

it('writes uploaded file streams in a child without moving file handling or metadata into it', function () {
    $upload = UploadedFile::fake()->createWithContent('report.txt', 'streamed contents');

    $version = VersionedStorage::put('report', $upload);

    expect(VersionedStorage::get('report', $version))->toBe('streamed contents')
        ->and(DB::table('versioned_storage_operations')->count())->toBe(0);
});

it('supports in-memory metadata databases because only filesystem operations are forked', function () {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('This validation requires fork support.');
    }

    $stage = VersionedStorage::stage('report', 'contents');

    expect($stage->publish())->toBe($stage->version)
        ->and(VersionedStorage::get('report'))->toBe('contents')
        ->and(DB::table('versioned_storage_operations')->count())->toBe(0);
});
