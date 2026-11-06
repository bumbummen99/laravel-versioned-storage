<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('preserves public disk URLs inside directory callbacks', function () {
    $url = null;
    $version = VersionedStorage::directory('reports', function (FilesystemAdapter $files) use (&$url): void {
        $files->put('summary.txt', 'contents');
        $url = $files->url('summary.txt');
    });

    expect($url)->toBe(Storage::disk('local')->url("reports/{$version}/summary.txt"));
});

it('preserves custom temporary URL callbacks configured on the original disk', function () {
    $disk = Storage::disk('local');
    $disk->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiration, array $options): string {
        return 'https://downloads.example.test/'.$path.'?name='.$options['name'];
    });
    $url = null;
    $version = VersionedStorage::directory('reports', function (FilesystemAdapter $files) use (&$url): void {
        expect($files->providesTemporaryUrls())->toBeTrue();
        $files->put('summary.txt', 'contents');
        $url = $files->temporaryUrl('summary.txt', now()->addMinute(), ['name' => 'report']);
    });

    expect($url)->toBe("https://downloads.example.test/reports/{$version}/summary.txt?name=report");
});

it('rejects temporary upload URLs even when the original disk supports them', function () {
    $disk = Mockery::mock(Storage::disk('local'))->makePartial();
    $disk->shouldNotReceive('temporaryUploadUrl');
    $disk->shouldReceive('providesTemporaryUploadUrls')->andReturn(true);
    Storage::set('local', $disk);

    expect(fn () => VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        expect($files->providesTemporaryUploadUrls())->toBeFalse();
        $files->put('summary.txt', 'contents');
        $files->temporaryUploadUrl('summary.txt', now()->addMinute());
    }))->toThrow(RuntimeException::class, 'Temporary upload URLs are not supported for versioned snapshots.');

    expect(VersionedStorage::directory('reports')->versions())->toBe([]);
    Storage::disk('local')->assertDirectoryEmpty('reports');
});
