<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Testing\VersionedStorageTesting;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('preserves scoped disk configuration across individual filesystem children', function () {
    Storage::set('tenant', Storage::build([
        'driver' => 'scoped',
        'disk' => [
            'driver' => 'local',
            'root' => Storage::disk('local')->path(''),
        ],
        'prefix' => 'tenants/acme',
    ]));
    VersionedStorageTesting::restoreUploadExecution();

    $version = VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        $files->put('one', 'contents');
        $files->copy('one', 'two');
        $files->move('two', 'three');
        expect($files->allFiles())->toBe(['one', 'three'])
            ->and($files->get('three'))->toBe('contents');
    }, disk: 'tenant');

    Storage::disk('local')->assertExists("tenants/acme/reports/{$version}/three");
    Storage::disk('local')->assertMissing("reports/{$version}/three");
});
