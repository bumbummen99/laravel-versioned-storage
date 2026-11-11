<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use SkyRaptor\LaravelVersionedStorage\Actions\CleanupStorage;
use SkyRaptor\LaravelVersionedStorage\Actions\ReconcileDeletions;
use SkyRaptor\LaravelVersionedStorage\Actions\RecoverUploads;
use SkyRaptor\LaravelVersionedStorage\Actions\RegisterResource;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

it('continues batch deletion after recovery errors and supports independent resource recovery', function () {
    $connection = DB::connection();
    $disk = Storage::disk('local');
    $retiredResource = new ResourceIdentity('batch-independent', 'local', false);
    $recovering = new ResourceIdentity('recover-independent', 'local', true);
    $retiredId = $connection->table('versioned_storage_resources')->insertGetId([
        'key' => $retiredResource->key, 'disk' => 'local', 'path' => $retiredResource->path,
        'is_directory' => false, 'current_version' => 'current',
    ]);
    $recoveringId = $connection->table('versioned_storage_resources')->insertGetId([
        'key' => $recovering->key, 'disk' => 'local', 'path' => $recovering->path, 'is_directory' => true,
    ]);
    $connection->table('versioned_storage_versions')->insert([
        ['resource_id' => $retiredId, 'version' => 'old', 'pending_delete' => true, 'created_at' => now()],
        ['resource_id' => $retiredId, 'version' => 'current', 'pending_delete' => false, 'created_at' => now()],
    ]);
    $connection->table('versioned_storage_stages')->insert([
        'resource_id' => $recoveringId, 'version' => 'interrupted',
        'state' => 'discarding', 'cleanup_after' => now()->subMinute(),
    ]);
    $connection->table('versioned_storage_operations')->insert([
        'id' => 'expired-operation', 'version' => 'interrupted', 'operation' => 'write', 'cleanup_after' => now()->subMinute(),
    ]);
    $disk->put('batch-independent/old', 'old');
    $disk->put('batch-independent/current', 'current');
    $disk->put('recover-independent/interrupted/one', 'discarded');
    $metadata = new ResourceMetadata($connection, $recovering);
    $ownership = new ResourceOwnership($connection, $recovering, $disk, app('filesystem'), config());
    $recovery = new RecoverUploads(
        $connection, $recovering, $metadata, new RegisterResource($connection, $recovering, $metadata, $ownership),
    );
    $failures = [];
    $report = static function (Throwable $failure) use (&$failures): void {
        $failures[] = $failure;
    };
    $cleanup = new CleanupStorage($connection, new ReconcileDeletions($connection));
    $connection->statement("CREATE TRIGGER reject_action_recovery BEFORE INSERT ON versioned_storage_versions WHEN NEW.pending_delete = 1 BEGIN SELECT RAISE(FAIL, 'recovery unavailable'); END");

    try {
        expect($cleanup->cleanup(1, $report))->toBeFalse()
            ->and($failures)->toHaveCount(1)
            ->and($failures[0])->toBeInstanceOf(QueryException::class);
        $disk->assertMissing('batch-independent/old');
        $disk->assertExists(['batch-independent/current', 'recover-independent/interrupted/one']);
        expect($metadata->stage('interrupted')->value('state'))->toBe('discarding')
            ->and($connection->table('versioned_storage_operations')->where('version', 'interrupted')->exists())->toBeTrue()
            ->and($metadata->versions()->exists())->toBeFalse()
            ->and($connection->transactionLevel())->toBe(0);
    } finally {
        $connection->statement('DROP TRIGGER reject_action_recovery');
    }

    $recovery->recover();
    expect($metadata->stage('interrupted')->exists())->toBeFalse()
        ->and($connection->table('versioned_storage_operations')->where('version', 'interrupted')->exists())->toBeFalse()
        ->and($metadata->versions()->where('version', 'interrupted')->value('pending_delete'))->toBe(1);
    $disk->assertExists('recover-independent/interrupted/one');
    expect($cleanup->cleanup(1, $report))->toBeTrue()
        ->and($failures)->toHaveCount(1)
        ->and($disk->get('batch-independent/current'))->toBe('current')
        ->and($connection->table('versioned_storage_resources')->where('id', $retiredId)->value('current_version'))->toBe('current');
    $disk->assertMissing('recover-independent/interrupted');
});

it('rechecks retirement and current references when reconciling individually supplied worker receipts', function () {
    $connection = DB::connection();
    $resource = new ResourceIdentity('final-guard', 'local', false);
    $resourceId = $connection->table('versioned_storage_resources')->insertGetId([
        'key' => $resource->key, 'disk' => 'local', 'path' => $resource->path,
        'is_directory' => false, 'current_version' => 'current',
    ]);
    $other = new ResourceIdentity('other-current', 'local', false);
    $otherId = $connection->table('versioned_storage_resources')->insertGetId([
        'key' => $other->key, 'disk' => 'local', 'path' => $other->path,
        'is_directory' => false, 'current_version' => 'other-current',
    ]);
    $unpublished = new ResourceIdentity('not-published', 'local', false);
    $connection->table('versioned_storage_resources')->insert([
        'key' => $unpublished->key, 'disk' => 'local', 'path' => $unpublished->path,
        'is_directory' => false, 'current_version' => null,
    ]);
    $connection->table('versioned_storage_versions')->insert([
        ['resource_id' => $resourceId, 'version' => 'retired', 'pending_delete' => true, 'created_at' => now()],
        ['resource_id' => $resourceId, 'version' => 'failed', 'pending_delete' => true, 'created_at' => now()],
        ['resource_id' => $resourceId, 'version' => 'current', 'pending_delete' => true, 'created_at' => now()],
        ['resource_id' => $resourceId, 'version' => 'readable', 'pending_delete' => false, 'created_at' => now()],
        ['resource_id' => $otherId, 'version' => 'other-current', 'pending_delete' => true, 'created_at' => now()],
    ]);
    $ids = $connection->table('versioned_storage_versions')->pluck('id', 'version')->all();
    $failures = [];
    $success = (new ReconcileDeletions($connection))->reconcile([
        ['version' => 'retired', 'error' => null],
        ['version' => 'failed', 'error' => 'filesystem unavailable'],
        ['version' => 'current', 'error' => null],
        ['version' => 'readable', 'error' => null],
        ['version' => 'other-current', 'error' => null],
    ], $ids, static function (Throwable $failure) use (&$failures): void {
        $failures[] = $failure;
    });

    expect($success)->toBeFalse()
        ->and($failures)->toHaveCount(1)
        ->and($failures[0]->getMessage())->toContain('filesystem unavailable')
        ->and($connection->table('versioned_storage_versions')->orderBy('id')->pluck('version')->all())
        ->toBe(['failed', 'current', 'readable', 'other-current'])
        ->and($connection->table('versioned_storage_resources')->where('id', $resourceId)->value('current_version'))->toBe('current')
        ->and($connection->table('versioned_storage_resources')->where('id', $otherId)->value('current_version'))->toBe('other-current');
});

it('reports a resource deletion failure, cleans later resources, and retries the failed work independently', function () {
    $connection = DB::connection();
    $disk = Storage::disk('local');
    foreach (['failed', 'other'] as $path) {
        $resource = new ResourceIdentity($path, 'local', false);
        $id = $connection->table('versioned_storage_resources')->insertGetId([
            'key' => $resource->key, 'disk' => 'local', 'path' => $path,
            'is_directory' => false, 'current_version' => "{$path}-current",
        ]);
        $connection->table('versioned_storage_versions')->insert([
            ['resource_id' => $id, 'version' => "{$path}-old", 'pending_delete' => true, 'created_at' => now()],
            ['resource_id' => $id, 'version' => "{$path}-current", 'pending_delete' => false, 'created_at' => now()],
        ]);
        $disk->put("{$path}/{$path}-old", 'retired');
        $disk->put("{$path}/{$path}-current", 'current');
    }
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with('failed/failed-old')->andThrow(new RuntimeException('disk unavailable'));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, $disk->getConfig()));
    $failures = [];
    $report = static function (Throwable $failure) use (&$failures): void {
        $failures[] = $failure;
    };
    $cleanup = new CleanupStorage($connection, new ReconcileDeletions($connection));

    try {
        expect($cleanup->cleanup(1, $report))->toBeFalse()
            ->and($failures)->toHaveCount(1)
            ->and($failures[0]->getMessage())->toContain('disk unavailable')
            ->and($connection->table('versioned_storage_versions')->where('version', 'failed-old')->exists())->toBeTrue()
            ->and($connection->table('versioned_storage_versions')->where('version', 'other-old')->exists())->toBeFalse();
        $disk->assertMissing('other/other-old');
        $disk->assertExists(['failed/failed-old', 'failed/failed-current', 'other/other-current']);
    } finally {
        Storage::set('local', $disk);
    }

    expect($cleanup->cleanup(1, $report))->toBeTrue()
        ->and($connection->table('versioned_storage_versions')->where('pending_delete', true)->exists())->toBeFalse()
        ->and($failures)->toHaveCount(1);
    $disk->assertMissing('failed/failed-old');
    $disk->assertExists(['failed/failed-current', 'other/other-current']);
});
