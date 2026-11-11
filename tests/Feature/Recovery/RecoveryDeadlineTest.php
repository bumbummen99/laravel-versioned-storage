<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SkyRaptor\LaravelVersionedStorage\Actions\CleanupSnapshots;
use SkyRaptor\LaravelVersionedStorage\Actions\RecoverUploads;
use SkyRaptor\LaravelVersionedStorage\Actions\RegisterResource;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

beforeEach(function (): void {
    $this->freezeTime();
    $this->connection = DB::connection();
    $this->resource = new ResourceIdentity('uncertain', 'local', false);
    $this->metadata = new ResourceMetadata($this->connection, $this->resource);
    $this->disk = Storage::disk('local');
    $ownership = new ResourceOwnership($this->connection, $this->resource, $this->disk, app('filesystem'), config());
    $registration = new RegisterResource($this->connection, $this->resource, $this->metadata, $ownership);
    $recovery = new RecoverUploads($this->connection, $this->resource, $this->metadata, $registration);
    $this->cleanup = new CleanupSnapshots(
        $this->connection, $this->metadata, $recovery,
        new SnapshotDeletion($this->connection, $this->resource, $this->metadata, $ownership, $this->disk),
    );
    $registration->register();
    $this->version = (string) Str::uuid();
    $this->deadline = now()->addMinute();
    $this->connection->table('versioned_storage_reservations')->insert(['version' => $this->version]);
    $this->connection->table('versioned_storage_stages')->insert([
        'resource_id' => $this->metadata->lockResource()->id, 'version' => $this->version,
        'state' => 'uploading', 'failed' => true, 'cleanup_after' => $this->deadline,
    ]);
    $this->connection->table('versioned_storage_operations')->insert([
        'id' => (string) Str::uuid(), 'version' => $this->version,
        'operation' => 'write', 'cleanup_after' => $this->deadline,
    ]);
    $this->disk->put("uncertain/{$this->version}", 'unacknowledged bytes');
});

it('refuses explicit discard one second before the uncertain writer deadline', function () {
    $this->travelTo($this->deadline->copy()->subSecond());

    expect(fn () => $this->cleanup->discard($this->version))
        ->toThrow(RuntimeException::class, 'must retain its journal until the cleanup deadline')
        ->and($this->metadata->stage($this->version)->value('state'))->toBe('uploading')
        ->and($this->connection->table('versioned_storage_operations')->count())->toBe(1)
        ->and($this->metadata->versions()->count())->toBe(0);
    $this->disk->assertExists("uncertain/{$this->version}");
});

it('retains the snapshot and journal when automatic cleanup runs before the deadline', function () {
    $this->travelTo($this->deadline->copy()->subSecond());

    $this->cleanup->cleanup();

    expect($this->metadata->stage($this->version)->value('state'))->toBe('uploading')
        ->and($this->connection->table('versioned_storage_operations')->count())->toBe(1)
        ->and($this->metadata->versions()->count())->toBe(0);
    $this->disk->assertExists("uncertain/{$this->version}");
});

it('discards the uncertain snapshot once its recovery deadline has elapsed', function (int $offset) {
    $this->travelTo($this->deadline->copy()->addSeconds($offset));

    $this->cleanup->discard($this->version);

    expect($this->metadata->stage($this->version)->exists())->toBeFalse()
        ->and($this->connection->table('versioned_storage_operations')->count())->toBe(0)
        ->and($this->metadata->versions()->count())->toBe(0)
        ->and($this->connection->table('versioned_storage_reservations')->where('version', $this->version)->exists())->toBeTrue();
    $this->disk->assertMissing("uncertain/{$this->version}");
})->with(['at the deadline' => 0, 'after the deadline' => 1]);

it('reclaims and deletes the uncertain snapshot once its recovery deadline has elapsed', function (int $offset) {
    $this->travelTo($this->deadline->copy()->addSeconds($offset));

    $this->cleanup->cleanup();

    expect($this->metadata->stage($this->version)->exists())->toBeFalse()
        ->and($this->connection->table('versioned_storage_operations')->count())->toBe(0)
        ->and($this->metadata->versions()->count())->toBe(0)
        ->and($this->connection->table('versioned_storage_reservations')->where('version', $this->version)->exists())->toBeTrue();
    $this->disk->assertMissing("uncertain/{$this->version}");
})->with(['at the deadline' => 0, 'after the deadline' => 1]);
