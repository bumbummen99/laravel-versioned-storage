<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SkyRaptor\LaravelVersionedStorage\Actions\PublishStage;
use SkyRaptor\LaravelVersionedStorage\Actions\RegisterResource;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

it('registers ownership independently of uploading or creating a snapshot', function () {
    $connection = DB::connection();
    $filesystem = Storage::disk('local');
    $resource = new ResourceIdentity('/reports/', 'local', true);
    $metadata = new ResourceMetadata($connection, $resource);
    $registration = new RegisterResource(
        $connection, $resource, $metadata, new ResourceOwnership($connection, $resource, $filesystem, app('filesystem'), config()),
    );

    $registration->register();
    $registration->register();

    expect($connection->table('versioned_storage_resources')->sole()->path)->toBe('reports')
        ->and($connection->table('versioned_storage_stages')->count())->toBe(0)
        ->and($connection->table('versioned_storage_reservations')->count())->toBe(0)
        ->and($connection->transactionLevel())->toBe(0);
    $filesystem->assertMissing('reports');

    $nested = new ResourceIdentity('reports/monthly', 'local', true);
    $conflicting = new RegisterResource(
        $connection, $nested, new ResourceMetadata($connection, $nested),
        new ResourceOwnership($connection, $nested, $filesystem, app('filesystem'), config()),
    );
    expect(fn () => $conflicting->register())->toThrow(InvalidArgumentException::class, 'overlaps registered resource')
        ->and($connection->table('versioned_storage_resources')->count())->toBe(1)
        ->and($connection->transactionLevel())->toBe(0);
});

it('scopes fresh SQLite metadata locks to one resource without owning its transaction', function () {
    $connection = DB::connection();
    $resource = new ResourceIdentity('metadata-owner', 'local', true);
    $metadata = new ResourceMetadata($connection, $resource);
    $other = new ResourceIdentity('metadata-other', 'local', true);
    $version = (string) Str::uuid();
    $otherVersion = (string) Str::uuid();
    foreach ([[$resource, $version], [$other, $otherVersion]] as [$identity, $stageVersion]) {
        $id = $connection->table('versioned_storage_resources')->insertGetId([
            'key' => $identity->key, 'disk' => 'local', 'path' => $identity->path, 'is_directory' => true,
        ]);
        $connection->table('versioned_storage_stages')->insert([
            'resource_id' => $id, 'version' => $stageVersion, 'state' => 'ready',
        ]);
    }

    $connection->beginTransaction();

    try {
        expect($metadata->lockResource()->key)->toBe($resource->key)
            ->and($metadata->lockStage($otherVersion, true))->toBeNull()
            ->and($metadata->stage($otherVersion)->exists())->toBeFalse()
            ->and($metadata->lockStage($version, true)->state)->toBe('ready');

        $metadata->stage($version)->update(['state' => 'discarding']);

        expect($metadata->lockStage($version, true)->state)->toBe('discarding')
            ->and($connection->transactionLevel())->toBe(1);
    } finally {
        $connection->rollBack();
    }

    expect($metadata->stage($version)->value('state'))->toBe('ready')
        ->and($connection->transactionLevel())->toBe(0);
    (new PublishStage($connection, $resource, $metadata))->publish($version);

    $connection->transaction(function () use ($metadata, $version): void {
        expect($metadata->lockResource()->current_version)->toBe($version)
            ->and($metadata->lockStage($version, true)->state)->toBe('published');
    });
});
