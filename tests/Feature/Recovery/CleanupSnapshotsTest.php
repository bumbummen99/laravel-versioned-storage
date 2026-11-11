<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use SkyRaptor\LaravelVersionedStorage\Actions\CleanupSnapshots;
use SkyRaptor\LaravelVersionedStorage\Actions\RecoverUploads;
use SkyRaptor\LaravelVersionedStorage\Actions\RegisterResource;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

beforeEach(function (): void {
    $this->connection = DB::connection();
    $this->resource = new ResourceIdentity('retired', 'local', false);
    $this->metadata = new ResourceMetadata($this->connection, $this->resource);
    $this->disk = Storage::disk('local');
    $resourceId = $this->connection->table('versioned_storage_resources')->insertGetId([
        'key' => $this->resource->key, 'disk' => 'local', 'path' => $this->resource->path,
        'is_directory' => false, 'current_version' => 'current',
    ]);
    foreach (['first', 'second', 'third', 'current'] as $version) {
        $this->connection->table('versioned_storage_versions')->insert([
            'resource_id' => $resourceId, 'version' => $version,
            'pending_delete' => $version !== 'current', 'created_at' => now(),
        ]);
        $this->disk->put("retired/{$version}", $version);
    }
    $this->cleanupFor = function (FilesystemAdapter $disk): CleanupSnapshots {
        $ownership = new ResourceOwnership($this->connection, $this->resource, $disk, app('filesystem'), config());
        $registration = new RegisterResource($this->connection, $this->resource, $this->metadata, $ownership);
        $recovery = new RecoverUploads($this->connection, $this->resource, $this->metadata, $registration);

        return new CleanupSnapshots(
            $this->connection, $this->metadata, $recovery,
            new SnapshotDeletion($this->connection, $this->resource, $this->metadata, $ownership, $disk),
        );
    };
});

it('reports every physical deletion failure, continues later candidates, and rethrows the first failure', function () {
    $firstFailure = new RuntimeException('first deletion refused');
    $secondFailure = new RuntimeException('second deletion refused');
    $adapter = Mockery::mock($this->disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with('retired/first')->andThrow($firstFailure);
    $adapter->shouldReceive('delete')->once()->with('retired/second')->andThrow($secondFailure);
    $disk = new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => true]);
    $reported = [];
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->twice()->andReturnUsing(static function (Throwable $failure) use (&$reported): void {
        $reported[] = $failure;
    });
    app()->instance(ExceptionHandler::class, $handler);
    $cleanup = ($this->cleanupFor)($disk);

    expect(fn () => $cleanup->cleanup())->toThrow($firstFailure)
        ->and($reported)->toBe([$firstFailure, $secondFailure])
        ->and($this->metadata->versions()->orderBy('id')->pluck('version')->all())->toBe(['first', 'second', 'current'])
        ->and($this->metadata->lockResource()->current_version)->toBe('current')
        ->and($this->connection->transactionLevel())->toBe(0);
    $this->disk->assertMissing('retired/third');
    $this->disk->assertExists(['retired/first', 'retired/second', 'retired/current']);
});

it('retries retained cleanup work independently of publication after a filesystem failure', function () {
    $failure = new RuntimeException('first deletion refused');
    $adapter = Mockery::mock($this->disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with('retired/first')->andThrow($failure);
    $disk = new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => true]);
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->once()->with($failure);
    app()->instance(ExceptionHandler::class, $handler);

    expect(fn () => ($this->cleanupFor)($disk)->cleanup())->toThrow($failure)
        ->and($this->metadata->versions()->orderBy('id')->pluck('version')->all())->toBe(['first', 'current']);

    ($this->cleanupFor)($this->disk)->cleanup();

    expect($this->metadata->versions()->sole()->version)->toBe('current')
        ->and($this->metadata->lockResource()->current_version)->toBe('current');
    $this->disk->assertMissing(['retired/first', 'retired/second', 'retired/third']);
    $this->disk->assertExists('retired/current');
});

it('never physically removes a current snapshot even when its retirement flag is corrupt', function () {
    $this->connection->table('versioned_storage_versions')->where('version', 'current')->update(['pending_delete' => true]);

    ($this->cleanupFor)($this->disk)->cleanup();

    expect($this->metadata->versions()->sole()->version)->toBe('current')
        ->and($this->metadata->versions()->sole()->pending_delete)->toBe(1)
        ->and($this->metadata->lockResource()->current_version)->toBe('current')
        ->and($this->disk->get('retired/current'))->toBe('current');
    $this->disk->assertMissing(['retired/first', 'retired/second', 'retired/third']);
});
