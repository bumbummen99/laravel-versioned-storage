<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use SkyRaptor\LaravelVersionedStorage\Actions\CleanupSnapshots;
use SkyRaptor\LaravelVersionedStorage\Actions\PublishStage;
use SkyRaptor\LaravelVersionedStorage\Actions\RecoverUploads;
use SkyRaptor\LaravelVersionedStorage\Actions\RegisterResource;
use SkyRaptor\LaravelVersionedStorage\Actions\UploadStages;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Database\UploadJournal;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

it('retains failed completion and cleanup intent without exposing a partial publication', function (ResourceIdentity $resource, ?string $current) {
    $this->freezeTime();
    $connection = DB::connection();
    $disk = Storage::disk('local');
    $metadata = new ResourceMetadata($connection, $resource);
    $id = $connection->table('versioned_storage_resources')->insertGetId([
        'key' => $resource->key, 'disk' => 'local', 'path' => $resource->path,
        'is_directory' => $resource->directory, 'current_version' => $current,
    ]);

    if ($current !== null) {
        $connection->table('versioned_storage_versions')->insert([
            'resource_id' => $id, 'version' => $current, 'created_at' => now(),
        ]);
        $disk->put($resource->path.'/'.$current.($resource->directory ? '/one' : ''), 'original');
    }
    $deadline = now()->addMinute();
    $connection->table('versioned_storage_reservations')->insert(['version' => 'incomplete']);
    $connection->table('versioned_storage_stages')->insert([
        'resource_id' => $id, 'version' => 'incomplete', 'state' => 'uploading', 'cleanup_after' => $deadline,
    ]);
    $path = $resource->path.'/incomplete'.($resource->directory ? '/one' : '');
    $disk->put($path, 'unpublished');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $deletionFailure = new RuntimeException('snapshot deletion refused');
    $adapter->shouldReceive($resource->directory ? 'deleteDirectory' : 'delete')->once()->andThrow($deletionFailure);
    $refusingDisk = new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => true]);
    $ownership = new ResourceOwnership($connection, $resource, $refusingDisk, app('filesystem'), config());
    $deletion = new SnapshotDeletion($connection, $resource, $metadata, $ownership, $refusingDisk);
    $stages = new UploadStages(
        $connection, $resource, $metadata, new UploadJournal($connection, $metadata),
        new PublishStage($connection, $resource, $metadata), $deletion,
    );
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->once()->with(Mockery::type(QueryException::class));
    app()->instance(ExceptionHandler::class, $handler);
    $connection->statement("CREATE TRIGGER reject_completion BEFORE INSERT ON versioned_storage_versions WHEN NEW.pending_delete = 0 BEGIN SELECT RAISE(FAIL, 'publication failed'); END");

    try {
        $publicationFailure = null;

        try {
            $stages->complete('incomplete', publish: true, cleanup: true);
        } catch (QueryException $failure) {
            $publicationFailure = $failure;
        }
        expect($publicationFailure)->toBeInstanceOf(QueryException::class)
            ->and($publicationFailure->getMessage())->toContain('publication failed');
        expect(fn () => $stages->fail('incomplete', $publicationFailure))->toThrow($deletionFailure);
    } finally {
        $connection->statement('DROP TRIGGER reject_completion');
    }

    expect($metadata->stage('incomplete')->value('state'))->toBe('uploading')
        ->and($metadata->stage('incomplete')->value('failed'))->toBe(0)
        ->and($metadata->lockResource()->current_version)->toBe($current)
        ->and($metadata->versions()->pluck('version')->all())->toBe($current === null ? [] : [$current])
        ->and($connection->table('versioned_storage_reservations')->where('version', 'incomplete')->exists())->toBeTrue()
        ->and($disk->get($path))->toBe('unpublished')
        ->and($connection->transactionLevel())->toBe(0);

    $ownership = new ResourceOwnership($connection, $resource, $disk, app('filesystem'), config());
    $registration = new RegisterResource($connection, $resource, $metadata, $ownership);
    $recovery = new RecoverUploads($connection, $resource, $metadata, $registration);
    $cleanup = new CleanupSnapshots(
        $connection, $metadata, $recovery, new SnapshotDeletion($connection, $resource, $metadata, $ownership, $disk),
    );
    $cleanup->cleanup();
    $disk->assertExists($path);
    $this->travelTo($deadline);
    $cleanup->cleanup();

    $disk->assertMissing($resource->path.'/incomplete');
    expect($metadata->stage('incomplete')->exists())->toBeFalse()
        ->and($metadata->versions()->where('pending_delete', true)->exists())->toBeFalse()
        ->and($connection->table('versioned_storage_reservations')->where('version', 'incomplete')->exists())->toBeTrue()
        ->and($metadata->lockResource()->current_version)->toBe($current);

    if ($current !== null) {
        expect($disk->get($resource->path.'/'.$current.($resource->directory ? '/one' : '')))->toBe('original');
    }
})->with([
    'established file' => [new ResourceIdentity('existing-file', 'local', false), 'original'],
    'new file' => [new ResourceIdentity('new-file', 'local', false), null],
    'established directory' => [new ResourceIdentity('existing-directory', 'local', true), 'original'],
    'new directory' => [new ResourceIdentity('new-directory', 'local', true), null],
]);

it('reports the original upload failure and propagates cleanup failure without losing its durable stage', function () {
    $connection = DB::connection();
    $disk = Storage::disk('local');
    $resource = new ResourceIdentity('failure-report', 'local', true);
    $metadata = new ResourceMetadata($connection, $resource);
    $id = $connection->table('versioned_storage_resources')->insertGetId([
        'key' => $resource->key, 'disk' => 'local', 'path' => $resource->path, 'is_directory' => true,
    ]);
    $connection->table('versioned_storage_stages')->insert([
        'resource_id' => $id, 'version' => 'incomplete', 'state' => 'uploading', 'cleanup_after' => now()->addMinute(),
    ]);
    $disk->put('failure-report/incomplete/one', 'incomplete');
    $uploadFailure = new RuntimeException('callback failed');
    $deletionFailure = new RuntimeException('snapshot deletion failed');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('deleteDirectory')->once()->andThrow($deletionFailure);
    $refusingDisk = new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => true]);
    $ownership = new ResourceOwnership($connection, $resource, $refusingDisk, app('filesystem'), config());
    $stages = new UploadStages(
        $connection, $resource, $metadata, new UploadJournal($connection, $metadata),
        new PublishStage($connection, $resource, $metadata),
        new SnapshotDeletion($connection, $resource, $metadata, $ownership, $refusingDisk),
    );
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->once()->with($uploadFailure);
    app()->instance(ExceptionHandler::class, $handler);

    expect(fn () => $stages->fail('incomplete', $uploadFailure))->toThrow($deletionFailure)
        ->and($metadata->versions()->exists())->toBeFalse()
        ->and($metadata->stage('incomplete')->value('state'))->toBe('uploading')
        ->and($connection->transactionLevel())->toBe(0);
    $disk->assertExists('failure-report/incomplete/one');
});
