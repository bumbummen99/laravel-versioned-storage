<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Actions\PublishStage;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotFilesystem;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

beforeEach(function (): void {
    $this->tenant = Storage::build([
        'driver' => 'scoped',
        'disk' => [
            'driver' => 'local',
            'root' => Storage::disk('local')->path(''),
        ],
        'prefix' => 'tenants/acme',
    ]);
});

it('writes and reads a directory snapshot through the selected disk prefix', function () {
    $snapshots = new SnapshotFilesystem($this->tenant, new ResourceIdentity('reports', 'tenant', true));
    $files = $snapshots->scoped('version');
    $files->put('summary.txt', 'contents');

    expect(Storage::disk('local')->get('tenants/acme/reports/version/summary.txt'))->toBe('contents');
    $this->tenant->put('reports/version/summary.txt', 'authoritative scoped contents');
    expect($files->get('summary.txt'))->toBe('authoritative scoped contents')
        ->and(Storage::disk('local')->exists('reports/version/summary.txt'))->toBeFalse();
});

it('writes and reads a file snapshot through the selected disk prefix', function () {
    $snapshots = new SnapshotFilesystem($this->tenant, new ResourceIdentity('report.txt', 'tenant', false));
    $files = $snapshots->scoped('version');
    $files->put('version', 'contents');

    expect(Storage::disk('local')->get('tenants/acme/report.txt/version'))->toBe('contents');
    $this->tenant->put('report.txt/version', 'authoritative scoped contents');
    expect($files->get('version'))->toBe('authoritative scoped contents')
        ->and(Storage::disk('local')->exists('report.txt/version'))->toBeFalse();
});

it('keeps paths listings and public URLs consistent with the physical snapshot', function () {
    $snapshots = new SnapshotFilesystem($this->tenant, new ResourceIdentity('reports', 'tenant', true));
    $files = $snapshots->scoped('version');
    $files->put('summary.txt', 'contents');

    expect($files->path('summary.txt'))->toBe($this->tenant->path('reports/version/summary.txt'))
        ->and($files->url('summary.txt'))->toBe($this->tenant->url('reports/version/summary.txt'))
        ->and($files->allFiles())->toBe(['summary.txt']);
});

it('keeps rolled back publication files inside the prefix until direct discard', function () {
    $resource = new ResourceIdentity('reports', 'tenant', false);
    $connection = DB::connection();
    $id = $connection->table('versioned_storage_resources')->insertGetId([
        'key' => $resource->key, 'disk' => 'tenant', 'path' => 'reports', 'is_directory' => false, 'current_version' => 'original',
    ]);
    $connection->table('versioned_storage_versions')->insert([
        'resource_id' => $id, 'version' => 'original', 'created_at' => now(),
    ]);
    $connection->table('versioned_storage_stages')->insert([
        'resource_id' => $id, 'version' => 'staged', 'state' => 'ready',
    ]);
    $this->tenant->put('reports/original', 'original');
    $this->tenant->put('reports/staged', 'staged');
    $metadata = new ResourceMetadata($connection, $resource);
    $publication = new PublishStage($connection, $resource, $metadata);

    $connection->beginTransaction();

    try {
        $publication->publish('staged', cleanup: true);
        expect($metadata->stage('staged')->value('state'))->toBe('published')
            ->and($this->tenant->get('reports/staged'))->toBe('staged');
    } finally {
        $connection->rollBack();
    }

    expect($metadata->stage('staged')->value('state'))->toBe('ready')
        ->and($this->tenant->get('reports/staged'))->toBe('staged');
    $deletion = new SnapshotDeletion(
        $connection, $resource, $metadata,
        new ResourceOwnership($connection, $resource, $this->tenant, app('filesystem'), config()),
        $this->tenant,
    );
    $deletion->delete('staged');

    expect(Storage::disk('local')->allFiles())->toBe(['tenants/acme/reports/original'])
        ->and($metadata->versions()->pluck('version')->all())->toBe(['original']);
});

it('preserves the combined prefix when scoping an already prefixed disk', function () {
    $nested = Storage::build([
        'driver' => 'scoped',
        'disk' => [
            'driver' => 'local',
            'root' => Storage::disk('local')->path(''),
            'prefix' => 'tenants',
        ],
        'prefix' => 'acme',
    ]);
    $snapshots = new SnapshotFilesystem($nested, new ResourceIdentity('reports', 'nested', false));
    $files = $snapshots->scoped('version');

    $files->put('version', 'contents');

    expect(Storage::disk('local')->allFiles())->toBe(['tenants/acme/reports/version'])
        ->and($files->get('version'))->toBe('contents');
});

it('physically removes a directory snapshot through the captured prefix', function () {
    $this->tenant->put('reports/old/nested/one', 'retired');
    $this->tenant->put('reports/current/one', 'current');

    SnapshotDeletion::remove($this->tenant, 'reports/old', true);

    expect(Storage::disk('local')->exists('tenants/acme/reports/old'))->toBeFalse()
        ->and($this->tenant->get('reports/current/one'))->toBe('current');
});

it('physically removes a file snapshot through the captured prefix', function () {
    $this->tenant->put('reports/old', 'retired');
    $this->tenant->put('reports/current', 'current');

    SnapshotDeletion::remove($this->tenant, 'reports/old', false);

    expect(Storage::disk('local')->exists('tenants/acme/reports/old'))->toBeFalse()
        ->and($this->tenant->get('reports/current'))->toBe('current');
});
