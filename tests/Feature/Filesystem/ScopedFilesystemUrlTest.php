<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotFilesystem;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

it('preserves public disk URLs when constructing a directory snapshot view', function () {
    $snapshot = new SnapshotFilesystem(Storage::disk('local'), new ResourceIdentity('reports', 'local', true));
    $files = $snapshot->scoped('version');
    $files->put('summary.txt', 'contents');

    expect($files->url('summary.txt'))->toBe(Storage::disk('local')->url('reports/version/summary.txt'));
});

it('preserves custom temporary URL callbacks configured on the original disk', function () {
    $disk = Storage::disk('local');
    $disk->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiration, array $options): string {
        return 'https://downloads.example.test/'.$path.'?name='.$options['name'];
    });
    $snapshot = new SnapshotFilesystem($disk, new ResourceIdentity('reports', 'local', true));
    $files = $snapshot->scoped('version');

    expect($files->providesTemporaryUrls())->toBeTrue()
        ->and($files->temporaryUrl('summary.txt', now()->addMinute(), ['name' => 'report']))
        ->toBe('https://downloads.example.test/reports/version/summary.txt?name=report');
});

it('rejects temporary upload URLs even when the original disk supports them', function () {
    $disk = Mockery::mock(Storage::disk('local'))->makePartial();
    $disk->shouldNotReceive('temporaryUploadUrl');
    $disk->shouldReceive('providesTemporaryUploadUrls')->andReturn(true);
    $snapshot = new SnapshotFilesystem($disk, new ResourceIdentity('reports', 'local', true));
    $files = $snapshot->scoped('version');

    expect($files->providesTemporaryUploadUrls())->toBeFalse()
        ->and(fn () => $files->temporaryUploadUrl('summary.txt', now()->addMinute()))
        ->toThrow(RuntimeException::class, 'Temporary upload URLs are not supported for versioned snapshots.');
});
