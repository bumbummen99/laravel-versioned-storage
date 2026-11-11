<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SkyRaptor\LaravelVersionedStorage\Actions\CleanupSnapshots;
use SkyRaptor\LaravelVersionedStorage\Actions\PublishStage;
use SkyRaptor\LaravelVersionedStorage\Actions\RecoverUploads;
use SkyRaptor\LaravelVersionedStorage\Actions\RegisterResource;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

beforeEach(function () {
    $this->connection = DB::connection();
    $this->resource = new ResourceIdentity('reclamation-actions', 'local', true);
    $this->disk = Storage::disk('local');
    $this->metadata = new ResourceMetadata($this->connection, $this->resource);
    $ownership = new ResourceOwnership($this->connection, $this->resource, $this->disk, app('filesystem'), config());
    $registration = new RegisterResource($this->connection, $this->resource, $this->metadata, $ownership);
    $this->registration = $registration;
    $this->recovery = new RecoverUploads($this->connection, $this->resource, $this->metadata, $registration);
    $this->cleanup = new CleanupSnapshots(
        $this->connection, $this->metadata, $this->recovery,
        new SnapshotDeletion($this->connection, $this->resource, $this->metadata, $ownership, $this->disk),
    );
});

it('rejects explicit discard of a published stage even after its version is retired', function () {
    $this->registration->register();
    $version = (string) Str::uuid();
    $resource = $this->metadata->lockResource();
    $this->connection->table('versioned_storage_stages')->insert([
        'resource_id' => $resource->id, 'version' => $version, 'state' => 'published',
    ]);
    $this->connection->table('versioned_storage_versions')->insert([
        'resource_id' => $resource->id, 'version' => $version, 'pending_delete' => true, 'created_at' => now(),
    ]);
    $this->disk->put($this->resource->path."/{$version}/one", 'retired');

    expect(fn () => $this->cleanup->discard($version))
        ->toThrow(RuntimeException::class, 'cannot be discarded')
        ->and($this->metadata->stage($version)->value('state'))->toBe('published')
        ->and($this->metadata->versions()->sole()->version)->toBe($version)
        ->and($this->metadata->versions()->sole()->pending_delete)->toBe(1);
    $this->disk->assertExists($this->resource->path."/{$version}/one");
});

it('retains a rolled-back directory stage and its original snapshot until the stage is explicitly discarded', function () {
    $this->registration->register();
    $resource = $this->metadata->lockResource();
    $this->connection->table('versioned_storage_resources')->where('id', $resource->id)->update(['current_version' => 'original']);
    $this->connection->table('versioned_storage_versions')->insert([
        'resource_id' => $resource->id, 'version' => 'original', 'created_at' => now(),
    ]);
    $this->connection->table('versioned_storage_reservations')->insert(['version' => 'replacement']);
    $this->connection->table('versioned_storage_stages')->insert([
        'resource_id' => $resource->id, 'version' => 'replacement', 'state' => 'ready',
    ]);
    $this->disk->put($this->resource->path.'/original/one', 'original');
    $this->disk->put($this->resource->path.'/replacement/one', 'replacement');
    $publication = new PublishStage($this->connection, $this->resource, $this->metadata);

    $this->connection->beginTransaction();
    $publication->publish('replacement', cleanup: true);
    $this->connection->rollBack();

    expect($this->metadata->lockResource()->current_version)->toBe('original')
        ->and($this->metadata->versions()->sole()->version)->toBe('original')
        ->and($this->metadata->versions()->sole()->pending_delete)->toBe(0)
        ->and($this->metadata->stage('replacement')->value('state'))->toBe('ready')
        ->and($this->disk->get($this->resource->path.'/original/one'))->toBe('original')
        ->and($this->disk->get($this->resource->path.'/replacement/one'))->toBe('replacement');

    $this->cleanup->discard('replacement');

    $this->disk->assertMissing($this->resource->path.'/replacement');
    expect($this->disk->get($this->resource->path.'/original/one'))->toBe('original')
        ->and($this->connection->table('versioned_storage_reservations')->where('version', 'replacement')->exists())->toBeTrue();
});

it('persists discard intent independently of physical deletion and metadata removal', function () {
    $stage = VersionedStorage::stageDirectory($this->resource->path, static fn (FilesystemAdapter $files): bool => $files->put('one', 'contents'));
    $this->connection->statement("CREATE TRIGGER reject_discard_lock BEFORE UPDATE ON versioned_storage_stages WHEN OLD.state = 'discarding' AND NEW.state = 'discarding' BEGIN SELECT RAISE(FAIL, 'deletion unavailable'); END");

    try {
        expect(fn () => $this->cleanup->discard($stage->version))->toThrow(QueryException::class, 'deletion unavailable');
    } finally {
        $this->connection->statement('DROP TRIGGER reject_discard_lock');
    }
    expect($this->metadata->stage($stage->version)->value('state'))->toBe('discarding')
        ->and($this->connection->transactionLevel())->toBe(0);
    $this->disk->assertExists($this->resource->path."/{$stage->version}/one");

    $this->cleanup->discard($stage->version);
    $this->cleanup->discard($stage->version);
    expect($this->metadata->stage($stage->version)->exists())->toBeFalse()
        ->and($this->connection->table('versioned_storage_reservations')->where('version', $stage->version)->exists())->toBeTrue();
    $this->disk->assertMissing($this->resource->path."/{$stage->version}");
});

it('rechecks operation deadlines before atomically transferring expired stage ownership', function (string $state) {
    $stage = VersionedStorage::stageDirectory($this->resource->path, static fn (FilesystemAdapter $files): bool => $files->put('one', 'contents'));
    $this->metadata->stage($stage->version)->update(['state' => $state, 'cleanup_after' => now()->subMinute()]);
    $this->connection->table('versioned_storage_operations')->insert([
        'id' => (string) Str::uuid(), 'version' => $stage->version, 'operation' => 'write', 'cleanup_after' => now()->addHour(),
    ]);
    $this->recovery->recover();
    expect($this->metadata->stage($stage->version)->exists())->toBeTrue()
        ->and($this->metadata->versions()->exists())->toBeFalse();

    $this->connection->table('versioned_storage_operations')->update(['cleanup_after' => now()]);
    $this->recovery->recover();
    $this->recovery->recover();
    expect($this->metadata->stage($stage->version)->exists())->toBeFalse()
        ->and($this->metadata->versions()->where('pending_delete', true)->exists())->toBeTrue();
    $this->disk->assertExists($this->resource->path."/{$stage->version}/one");

    $this->cleanup->cleanup();
    $this->disk->assertMissing($this->resource->path."/{$stage->version}/one");
})->with([
    'interrupted upload' => 'uploading',
    'interrupted discard' => 'discarding',
]);

it('never expires a ready stage even after all operation recovery deadlines pass', function () {
    $stage = VersionedStorage::stageDirectory($this->resource->path, fn (FilesystemAdapter $files) => $files->put('one', 'contents'));
    $this->metadata->stage($stage->version)->update(['cleanup_after' => now()->subMinute()]);
    $this->connection->table('versioned_storage_operations')->insert([
        'id' => (string) Str::uuid(),
        'version' => $stage->version,
        'operation' => 'write',
        'cleanup_after' => now()->addHour(),
    ]);

    $this->recovery->recover();

    expect($this->metadata->stage($stage->version)->exists())->toBeTrue()
        ->and($this->metadata->versions()->exists())->toBeFalse();

    $this->connection->table('versioned_storage_operations')->update(['cleanup_after' => now()]);
    $this->recovery->recover();
    $this->recovery->recover();

    expect($this->metadata->stage($stage->version)->exists())->toBeTrue()
        ->and($this->metadata->versions()->where('pending_delete', true)->exists())->toBeFalse();
    $this->disk->assertExists($this->resource->path."/{$stage->version}/one");

    $this->cleanup->cleanup();

    $this->disk->assertExists($this->resource->path."/{$stage->version}/one");
});

it('rechecks readiness when upload completion races expired-stage discovery', function () {
    $stage = VersionedStorage::stageDirectory($this->resource->path, static fn (FilesystemAdapter $files): bool => $files->put('one', 'completed'));
    $this->metadata->stage($stage->version)->update(['state' => 'uploading', 'cleanup_after' => now()->subMinute()]);
    $waiting = true;
    DB::listen(function (QueryExecuted $query) use ($stage, &$waiting): void {
        if ($waiting && $query->connection === $this->connection
            && str_starts_with($query->sql, 'select * from "versioned_storage_stages"')
            && str_contains($query->sql, 'order by "id" asc')) {
            $waiting = false;
            $this->metadata->stage($stage->version)->update(['state' => 'ready']);
        }
    });

    $this->recovery->recover();

    expect($waiting)->toBeFalse()
        ->and($this->metadata->stage($stage->version)->value('state'))->toBe('ready')
        ->and($this->metadata->versions()->exists())->toBeFalse();
    $this->disk->assertExists($this->resource->path."/{$stage->version}/one");
});

it('retains cleanup work idempotently without deleting its physical snapshot', function () {
    $version = (string) Str::uuid();
    $this->disk->put($this->resource->path."/{$version}/one", 'discarded');
    $this->recovery->retain($version);
    $this->recovery->retain($version);
    expect($this->metadata->versions()->sole()->pending_delete)->toBe(1)
        ->and($this->connection->table('versioned_storage_reservations')->count())->toBe(1)
        ->and($this->connection->table('versioned_storage_operations')->count())->toBe(0);
    $this->disk->assertExists($this->resource->path."/{$version}/one");
});

it('retains cleanup ownership through the handler for a later independent cleanup', function () {
    $handler = VersionedStorage::directory($this->resource->path);
    $current = $handler->write(static fn (FilesystemAdapter $files): bool => $files->put('one', 'current'));
    $version = (string) Str::uuid();
    $snapshot = $this->resource->path."/{$version}";
    $this->disk->put("{$snapshot}/one", 'abandoned');

    $handler->retainDiscard($version);
    $record = $this->metadata->versions()->where('version', $version)->sole();
    $handler->retainDiscard($version);

    expect($this->metadata->versions()->where('version', $version)->sole()->id)->toBe($record->id)
        ->and($record->pending_delete)->toBe(1)
        ->and($this->connection->table('versioned_storage_reservations')->where('version', $version)->count())->toBe(1)
        ->and($this->disk->get("{$snapshot}/one"))->toBe('abandoned')
        ->and($handler->versions())->toBe([$current])
        ->and($handler->get('one'))->toBe('current')
        ->and($this->connection->transactionLevel())->toBe(0);

    VersionedStorage::directory($this->resource->path)->cleanup();

    expect($this->metadata->versions()->where('version', $version)->exists())->toBeFalse()
        ->and($this->connection->table('versioned_storage_reservations')->where('version', $version)->exists())->toBeTrue()
        ->and($handler->versions())->toBe([$current])
        ->and($handler->get('one'))->toBe('current');
    $this->disk->assertMissing($snapshot);
});
