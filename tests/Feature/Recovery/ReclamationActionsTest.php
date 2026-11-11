<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SkyRaptor\LaravelVersionedStorage\Actions\CleanupSnapshots;
use SkyRaptor\LaravelVersionedStorage\Actions\ReconcileDeletions;
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
    $ownership = new ResourceOwnership($this->connection, $this->resource, $this->disk);
    $registration = new RegisterResource($this->connection, $this->resource, $this->metadata, $ownership);
    $this->recovery = new RecoverUploads($this->connection, $this->resource, $this->metadata, $registration);
    $this->cleanup = new CleanupSnapshots(
        $this->connection, $this->metadata, $this->recovery,
        new SnapshotDeletion($this->connection, $this->resource, $this->metadata, $ownership, $this->disk),
    );
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
    $eligible = $state !== 'ready';
    expect($this->metadata->stage($stage->version)->exists())->toBe(! $eligible)
        ->and($this->metadata->versions()->where('pending_delete', true)->exists())->toBe($eligible);
    $this->disk->assertExists($this->resource->path."/{$stage->version}/one");

    $this->cleanup->cleanup();
    expect($this->disk->exists($this->resource->path."/{$stage->version}/one"))->toBe(! $eligible);
})->with(['uploading', 'discarding', 'ready']);

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

it('validates the whole worker receipt set before removing any retired metadata', function (string $invalid) {
    $first = VersionedStorage::put('batch-actions', 'original');
    VersionedStorage::put('batch-actions', 'current', cleanup: true);
    $id = (int) $this->connection->table('versioned_storage_versions')->where('version', $first)->value('id');
    $receipt = ['version' => $first, 'error' => null];
    $results = match ($invalid) {
        'missing' => [],
        'duplicate' => [$receipt, $receipt],
        'unexpected' => [$receipt, ['version' => 'unrequested', 'error' => null]],
    };

    expect(fn () => (new ReconcileDeletions($this->connection))->reconcile(
        $results, [$first => $id], static function (Throwable $failure): never {
            throw $failure;
        },
    ))->toThrow(RuntimeException::class);
    expect($this->connection->table('versioned_storage_versions')->where('id', $id)->value('pending_delete'))->toBe(1);
})->with(['missing', 'duplicate', 'unexpected']);
