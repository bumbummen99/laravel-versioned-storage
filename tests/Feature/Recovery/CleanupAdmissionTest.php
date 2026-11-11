<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Actions\CleanupSnapshots;
use SkyRaptor\LaravelVersionedStorage\Actions\RecoverUploads;
use SkyRaptor\LaravelVersionedStorage\Actions\RegisterResource;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

beforeEach(function (): void {
    $this->connection = DB::connection();
    $resource = new ResourceIdentity('cleanup-admission', 'local', false);
    $this->metadata = new ResourceMetadata($this->connection, $resource);
    $this->disk = Storage::disk('local');
    $id = $this->connection->table('versioned_storage_resources')->insertGetId([
        'key' => $resource->key, 'disk' => 'local', 'path' => $resource->path,
        'is_directory' => false, 'current_version' => 'current',
    ]);
    $this->connection->table('versioned_storage_versions')->insert([
        ['resource_id' => $id, 'version' => 'old', 'pending_delete' => true, 'created_at' => now()],
        ['resource_id' => $id, 'version' => 'current', 'pending_delete' => false, 'created_at' => now()],
    ]);
    $this->connection->table('versioned_storage_stages')->insert([
        'resource_id' => $id, 'version' => 'completed', 'state' => 'ready',
    ]);
    foreach (['old', 'current', 'completed'] as $version) {
        $this->disk->put("cleanup-admission/{$version}", $version);
    }
    $ownership = new ResourceOwnership($this->connection, $resource, $this->disk, app('filesystem'), config());
    $registration = new RegisterResource($this->connection, $resource, $this->metadata, $ownership);
    $recovery = new RecoverUploads($this->connection, $resource, $this->metadata, $registration);
    $this->cleanup = new CleanupSnapshots(
        $this->connection, $this->metadata, $recovery,
        new SnapshotDeletion($this->connection, $resource, $this->metadata, $ownership, $this->disk),
    );
});

it('leaves caller Laravel transaction ownership and snapshots unchanged when cleanup is refused', function () {
    $this->connection->beginTransaction();

    try {
        expect(fn () => $this->cleanup->cleanup())->toThrow(RuntimeException::class, 'Version cleanup cannot run')
            ->and($this->connection->transactionLevel())->toBe(1)
            ->and($this->connection->getPdo()->inTransaction())->toBeTrue();
        $this->disk->assertExists(['cleanup-admission/old', 'cleanup-admission/current', 'cleanup-admission/completed']);
    } finally {
        $this->connection->rollBack();
    }
    expect($this->metadata->versions()->count())->toBe(2)
        ->and($this->metadata->lockResource()->current_version)->toBe('current');
});

it('leaves caller raw PDO transaction ownership and snapshots unchanged when cleanup is refused', function () {
    $this->connection->getPdo()->beginTransaction();

    try {
        expect(fn () => $this->cleanup->cleanup())->toThrow(RuntimeException::class, 'Version cleanup cannot run')
            ->and($this->connection->transactionLevel())->toBe(0)
            ->and($this->connection->getPdo()->inTransaction())->toBeTrue();
        $this->disk->assertExists(['cleanup-admission/old', 'cleanup-admission/current', 'cleanup-admission/completed']);
    } finally {
        $this->connection->getPdo()->rollBack();
    }
    expect($this->metadata->versions()->count())->toBe(2)
        ->and($this->metadata->lockResource()->current_version)->toBe('current');
});

it('does not mark a stage discarding inside a caller Laravel transaction', function () {
    $this->connection->beginTransaction();

    try {
        expect(fn () => $this->cleanup->discard('completed'))->toThrow(RuntimeException::class, 'Uploads cannot run')
            ->and($this->connection->transactionLevel())->toBe(1)
            ->and($this->connection->getPdo()->inTransaction())->toBeTrue();
        $this->disk->assertExists('cleanup-admission/completed');
    } finally {
        $this->connection->rollBack();
    }
    expect($this->metadata->stage('completed')->value('state'))->toBe('ready');
});

it('does not mark a stage discarding inside a caller raw PDO transaction', function () {
    $this->connection->getPdo()->beginTransaction();

    try {
        expect(fn () => $this->cleanup->discard('completed'))->toThrow(RuntimeException::class, 'Uploads cannot run')
            ->and($this->connection->transactionLevel())->toBe(0)
            ->and($this->connection->getPdo()->inTransaction())->toBeTrue();
        $this->disk->assertExists('cleanup-admission/completed');
    } finally {
        $this->connection->getPdo()->rollBack();
    }
    expect($this->metadata->stage('completed')->value('state'))->toBe('ready');
});
