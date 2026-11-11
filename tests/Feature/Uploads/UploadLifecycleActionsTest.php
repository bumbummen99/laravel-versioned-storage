<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SkyRaptor\LaravelVersionedStorage\Actions\PublishStage;
use SkyRaptor\LaravelVersionedStorage\Actions\ReadSnapshot;
use SkyRaptor\LaravelVersionedStorage\Actions\RegisterResource;
use SkyRaptor\LaravelVersionedStorage\Actions\ReserveDestination;
use SkyRaptor\LaravelVersionedStorage\Actions\UploadSnapshot;
use SkyRaptor\LaravelVersionedStorage\Actions\UploadStages;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Database\UploadJournal;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotFilesystem;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestUploadClock;

beforeEach(function () {
    $this->connection = DB::connection();
    $this->resource = new ResourceIdentity('actions', 'local', true);
    $this->disk = Storage::disk('local');
    $this->metadata = new ResourceMetadata($this->connection, $this->resource);
    $ownership = new ResourceOwnership($this->connection, $this->resource, $this->disk, app('filesystem'), config());
    $this->registration = new RegisterResource($this->connection, $this->resource, $this->metadata, $ownership);
    $this->snapshots = new SnapshotFilesystem($this->disk, $this->resource);
    $this->reservation = new ReserveDestination($this->connection, $this->resource, $this->disk);
    $this->publication = new PublishStage($this->connection, $this->resource, $this->metadata);
    $this->uploads = new UploadStages(
        $this->connection, $this->resource, $this->metadata,
        new UploadJournal($this->connection, $this->metadata), $this->publication,
        new SnapshotDeletion($this->connection, $this->resource, $this->metadata, $ownership, $this->disk),
    );
    $this->workflow = new UploadSnapshot(
        $this->connection, $this->resource, $this->snapshots, $this->registration, $this->reservation, $this->uploads,
    );
});

it('resolves execution settings per upload and rejects invalid settings before reservation', function () {
    config()->set('laravel-versioned-storage.cleanup_grace', -1);
    expect(fn () => $this->workflow->stage(static function (): void {}))->toThrow(RuntimeException::class, 'Cleanup grace')
        ->and($this->connection->table('versioned_storage_resources')->count())->toBe(0)
        ->and($this->connection->table('versioned_storage_reservations')->count())->toBe(0);
    $this->disk->assertMissing('actions');

    $clock = new TestUploadClock(now()->startOfSecond());
    app()->instance(UploadClock::class, $clock);
    config()->set('laravel-versioned-storage.cleanup_grace', 30);
    foreach ([15, 25] as $timeout) {
        config()->set('laravel-versioned-storage.upload_timeout', $timeout);
        $this->workflow->stage(function (FilesystemAdapter $files, string $version) use ($clock, $timeout): void {
            expect($this->metadata->stage($version)->value('cleanup_after'))
                ->toBe($clock->now()->addSeconds($timeout + 30)->toDateTimeString());
            $files->put('one', 'contents');
        });
    }
});

it('retains the UUID reservation when physical destination admission fails', function () {
    $version = Str::uuid();
    Str::createUuidsUsing(static fn () => $version);
    $this->disk->put("actions/{$version}/one", 'unregistered');
    $this->registration->register();

    try {
        expect(fn () => $this->reservation->reserve(['timeout' => 10, 'fork' => false]))
            ->toThrow(RuntimeException::class, 'already exists');
    } finally {
        Str::createUuidsNormally();
    }

    expect($this->connection->table('versioned_storage_reservations')->value('version'))->toBe((string) $version)
        ->and($this->connection->table('versioned_storage_stages')->count())->toBe(0)
        ->and($this->disk->get("actions/{$version}/one"))->toBe('unregistered');
});

it('commits stage intent before IO and closes callback filesystems before completion', function () {
    $execution = ['timeout' => 10, 'fork' => false];
    $this->registration->register();
    $destination = $this->reservation->reserve($execution);
    $version = $destination['version'];
    $operation = $this->uploads->begin($version, $execution, $destination['configuration'], 30);
    expect($this->metadata->stage($version)->value('state'))->toBe('uploading')
        ->and($this->connection->transactionLevel())->toBe(0);
    $this->disk->assertMissing("actions/{$version}");
    $retained = $this->snapshots->scoped($version, $operation);
    $retained->makeDirectory('');
    $retained->put('one', 'completed');
    $operation->assertHealthy();
    $operation->close();

    expect($this->metadata->stage($version)->value('state'))->toBe('uploading')
        ->and(fn () => $retained->put('late', 'forbidden'))->toThrow(RuntimeException::class, 'already finished');
    $this->uploads->complete($version, false, false);
    expect($this->metadata->stage($version)->value('state'))->toBe('ready');
    $this->publication->publish($version);
    $reads = new ReadSnapshot($this->connection, $this->resource, $this->metadata, $this->snapshots);
    expect($reads->versions())->toBe([$version])
        ->and($reads->get('one'))->toBe('completed');

    $version = $this->workflow->stage(function (FilesystemAdapter $files, string $version) use (&$retained): void {
        expect($this->metadata->stage($version)->value('state'))->toBe('uploading')
            ->and($this->connection->transactionLevel())->toBe(0);
        $retained = $files;
        $files->put('one', 'workflow contents');
    });
    expect($this->metadata->stage($version)->value('state'))->toBe('ready')
        ->and(fn () => $retained->put('late', 'forbidden'))->toThrow(RuntimeException::class, 'already finished');
});

it('closes the callback filesystem when the callback throws', function () {
    $retained = null;
    $version = null;
    $callback = function (FilesystemAdapter $files, string $id) use (&$retained, &$version): void {
        $retained = $files;
        $version = $id;
        $files->put('one', 'completed');

        throw new RuntimeException('callback failed');
    };

    expect(fn () => $this->workflow->stage($callback, true))
        ->toThrow(RuntimeException::class, 'callback failed');

    expect($retained)->toBeInstanceOf(FilesystemAdapter::class)
        ->and(fn () => $retained->put('late', 'forbidden'))->toThrow(RuntimeException::class, 'already finished')
        ->and($this->metadata->versions()->exists())->toBeFalse();
    $this->disk->assertMissing("actions/{$version}/late");
});

it('closes the callback filesystem when its callback leaves a metadata transaction open', function () {
    $retained = null;
    $version = null;
    $callback = function (FilesystemAdapter $files, string $id) use (&$retained, &$version): void {
        $retained = $files;
        $version = $id;
        $files->put('one', 'completed');
        $this->connection->beginTransaction();
    };

    try {
        expect(fn () => $this->workflow->stage($callback, true))
            ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction');
        expect($retained)->toBeInstanceOf(FilesystemAdapter::class)
            ->and(fn () => $retained->put('late', 'forbidden'))->toThrow(RuntimeException::class, 'already finished')
            ->and($this->metadata->versions()->exists())->toBeFalse();
        $this->disk->assertMissing("actions/{$version}/late");
    } finally {
        $this->connection->rollBack();
    }
});

it('closes the callback filesystem before handling publication failure', function () {
    $retained = null;
    $version = null;
    $callback = function (FilesystemAdapter $files, string $id) use (&$retained, &$version): void {
        $retained = $files;
        $version = $id;
        $files->put('one', 'completed');
    };
    $this->connection->statement("CREATE TRIGGER reject_workflow_publication BEFORE INSERT ON versioned_storage_versions BEGIN SELECT RAISE(FAIL, 'publication unavailable'); END");

    try {
        expect(fn () => $this->workflow->stage($callback, true))
            ->toThrow(QueryException::class, 'publication unavailable');
        expect($retained)->toBeInstanceOf(FilesystemAdapter::class)
            ->and(fn () => $retained->put('late', 'forbidden'))->toThrow(RuntimeException::class, 'already finished')
            ->and($this->metadata->versions()->exists())->toBeFalse();
        $this->disk->assertMissing("actions/{$version}/late");
    } finally {
        $this->connection->statement('DROP TRIGGER reject_workflow_publication');
    }
});

it('rolls back readiness with publication when the atomic completion action fails', function () {
    $execution = ['timeout' => 10, 'fork' => false];
    $this->registration->register();
    $destination = $this->reservation->reserve($execution);
    $version = $destination['version'];
    $operation = $this->uploads->begin($version, $execution, $destination['configuration'], 30);
    $files = $this->snapshots->scoped($version, $operation);
    $files->makeDirectory('');
    $files->put('one', 'completed');
    $operation->close();
    $this->connection->statement("CREATE TRIGGER reject_action_publication BEFORE INSERT ON versioned_storage_versions BEGIN SELECT RAISE(FAIL, 'publication unavailable'); END");

    try {
        expect(fn () => $this->uploads->complete($version, true, true))
            ->toThrow(QueryException::class, 'publication unavailable');
    } finally {
        $this->connection->statement('DROP TRIGGER reject_action_publication');
    }

    expect($this->metadata->stage($version)->value('state'))->toBe('uploading')
        ->and($this->connection->table('versioned_storage_versions')->count())->toBe(0)
        ->and($this->connection->transactionLevel())->toBe(0);
    $this->disk->assertExists("actions/{$version}/one");
});

it('marks failure while retaining uncertain writes and their durable operation records', function () {
    $execution = ['timeout' => 10, 'fork' => false];
    $this->registration->register();
    $destination = $this->reservation->reserve($execution);
    $version = $destination['version'];
    $operation = $this->uploads->begin($version, $execution, $destination['configuration'], 30);
    $this->disk->put("actions/{$version}/one", 'uncertain');
    $this->connection->table('versioned_storage_operations')->insert([
        'id' => (string) Str::uuid(), 'version' => $version, 'operation' => 'write', 'cleanup_after' => now()->addHour(),
    ]);
    $operation->close();
    $failure = new RuntimeException('operation acknowledgement lost');

    expect(fn () => $this->uploads->fail($version, $failure))->toThrow($failure)
        ->and($this->metadata->stage($version)->value('failed'))->toBe(1)
        ->and($this->connection->table('versioned_storage_operations')->count())->toBe(1)
        ->and((new ReadSnapshot($this->connection, $this->resource, $this->metadata, $this->snapshots))->versions())->toBe([]);
    $this->disk->assertExists("actions/{$version}/one");
});
