<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Actions\RegisterResource;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('registers ownership independently of uploading or creating a snapshot', function () {
    $connection = DB::connection();
    $filesystem = Storage::disk('local');
    $resource = new ResourceIdentity('/reports/', 'local', true);
    $metadata = new ResourceMetadata($connection, $resource);
    $registration = new RegisterResource(
        $connection, $resource, $metadata, new ResourceOwnership($connection, $resource, $filesystem),
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
        new ResourceOwnership($connection, $nested, $filesystem),
    );
    expect(fn () => $conflicting->register())->toThrow(InvalidArgumentException::class, 'overlaps registered resource')
        ->and($connection->table('versioned_storage_resources')->count())->toBe(1)
        ->and($connection->transactionLevel())->toBe(0);
});

it('scopes fresh metadata locks to one resource without owning its transaction', function (bool $skipLocked) {
    $connection = DB::connection();
    $resource = new ResourceIdentity('metadata-owner', 'local', true);
    $metadata = new ResourceMetadata($connection, $resource);
    $stage = VersionedStorage::stageDirectory($resource->path, static fn (FilesystemAdapter $files): bool => $files->put('one', 'owned'));
    $other = VersionedStorage::stageDirectory('metadata-other', static fn (FilesystemAdapter $files): bool => $files->put('one', 'other'));

    $connection->beginTransaction();

    try {
        expect($metadata->lockResource()->key)->toBe($resource->key)
            ->and($metadata->lockStage($other->version, $skipLocked))->toBeNull()
            ->and($metadata->stage($other->version)->exists())->toBeFalse()
            ->and($metadata->lockStage($stage->version, $skipLocked)->state)->toBe('ready');

        $metadata->stage($stage->version)->update(['state' => 'discarding']);

        expect($metadata->lockStage($stage->version, $skipLocked)->state)->toBe('discarding')
            ->and($connection->transactionLevel())->toBe(1);
    } finally {
        $connection->rollBack();
    }

    expect($metadata->stage($stage->version)->value('state'))->toBe('ready')
        ->and($connection->transactionLevel())->toBe(0);
    $stage->publish();

    $connection->transaction(function () use ($metadata, $stage, $skipLocked): void {
        expect($metadata->lockResource()->current_version)->toBe($stage->version)
            ->and($metadata->lockStage($stage->version, $skipLocked)->state)->toBe('published');
    });

    Storage::disk('local')->assertExists(["{$resource->path}/{$stage->version}/one", "metadata-other/{$other->version}/one"]);
})->with([false, true]);
