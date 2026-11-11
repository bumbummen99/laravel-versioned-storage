<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Actions\CleanupSnapshots;
use SkyRaptor\LaravelVersionedStorage\Actions\PublishStage;
use SkyRaptor\LaravelVersionedStorage\Actions\ReadSnapshot;
use SkyRaptor\LaravelVersionedStorage\Actions\RecoverUploads;
use SkyRaptor\LaravelVersionedStorage\Actions\RegisterResource;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotFilesystem;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

beforeEach(function (): void {
    $this->connection = DB::connection();
    $this->disk = Storage::disk('local');
    $this->resource = new ResourceIdentity('event-publication', 'local', false);
    $this->metadata = new ResourceMetadata($this->connection, $this->resource);
    $this->resourceId = $this->connection->table('versioned_storage_resources')->insertGetId([
        'key' => $this->resource->key, 'disk' => 'local', 'path' => $this->resource->path,
        'is_directory' => false, 'current_version' => 'original',
    ]);
    $this->connection->table('versioned_storage_versions')->insert([
        'resource_id' => $this->resourceId, 'version' => 'original', 'created_at' => now(),
    ]);
    $this->connection->table('versioned_storage_stages')->insert([
        'resource_id' => $this->resourceId, 'version' => 'replacement', 'state' => 'ready',
    ]);
    $this->disk->put('event-publication/original', 'original contents');
    $this->disk->put('event-publication/replacement', 'completed contents');
    $this->publication = new PublishStage($this->connection, $this->resource, $this->metadata);
    $this->reads = new ReadSnapshot(
        $this->connection, $this->resource, $this->metadata, new SnapshotFilesystem($this->disk, $this->resource),
    );
    $ownership = new ResourceOwnership($this->connection, $this->resource, $this->disk, app('filesystem'), config());
    $registration = new RegisterResource($this->connection, $this->resource, $this->metadata, $ownership);
    $recovery = new RecoverUploads($this->connection, $this->resource, $this->metadata, $registration);
    $this->cleanup = new CleanupSnapshots(
        $this->connection, $this->metadata, $recovery,
        new SnapshotDeletion($this->connection, $this->resource, $this->metadata, $ownership, $this->disk),
    );
});

afterEach(function (): void {
    Event::forget(TransactionBeginning::class);
    Event::forget(TransactionCommitted::class);
    Event::forget(TransactionCommitting::class);

    if ($this->connection->transactionLevel() > 0) {
        $this->connection->rollBack(0);
    } elseif ($this->connection->getPdo()->inTransaction()) {
        $this->connection->getPdo()->rollBack();
    }
});

it('preserves a raw PDO transaction when refusing publication and can publish after its caller rolls back', function () {
    $this->connection->getPdo()->beginTransaction();

    try {
        expect(fn () => $this->publication->publish('replacement', cleanup: true))
            ->toThrow(RuntimeException::class, 'not raw PDO transactions')
            ->and($this->connection->transactionLevel())->toBe(0)
            ->and($this->connection->getPdo()->inTransaction())->toBeTrue()
            ->and($this->metadata->stage('replacement')->value('state'))->toBe('ready')
            ->and($this->metadata->lockResource()->current_version)->toBe('original');
    } finally {
        $this->connection->getPdo()->rollBack();
    }

    $this->publication->publish('replacement', cleanup: true);

    expect($this->reads->get(''))->toBe('completed contents')
        ->and($this->reads->versions())->toBe(['replacement'])
        ->and($this->metadata->stage('replacement')->value('state'))->toBe('published')
        ->and($this->metadata->versions()->where('version', 'original')->value('pending_delete'))->toBe(1);
    $this->disk->assertExists(['event-publication/original', 'event-publication/replacement']);
});

it('keeps publication committed after a committed listener throws and makes its retry a no-op', function () {
    Event::listen(TransactionCommitted::class, static fn () => throw new RuntimeException('listener failed'));

    try {
        expect(fn () => $this->publication->publish('replacement'))->toThrow(RuntimeException::class, 'listener failed');
    } finally {
        Event::forget(TransactionCommitted::class);
    }
    expect($this->metadata->lockResource()->current_version)->toBe('replacement')
        ->and($this->metadata->versions()->count())->toBe(2)
        ->and($this->connection->transactionLevel())->toBe(0)
        ->and($this->connection->getPdo()->inTransaction())->toBeFalse();
    $this->connection->table('versioned_storage_stages')->insert([
        'resource_id' => $this->resourceId, 'version' => 'later', 'state' => 'ready',
    ]);
    $this->publication->publish('later');

    $this->publication->publish('replacement');

    expect($this->metadata->lockResource()->current_version)->toBe('later')
        ->and($this->metadata->versions()->orderBy('id')->pluck('version')->all())->toBe(['original', 'replacement', 'later']);
    $this->disk->assertExists(['event-publication/original', 'event-publication/replacement']);
});

it('restores a ready stage and retirement flags when a nested committed listener throws before outer rollback', function () {
    $this->connection->beginTransaction();
    Event::listen(TransactionCommitted::class, static function ($event): void {
        if ($event->connection->transactionLevel() === 1) {
            throw new RuntimeException('nested listener failed');
        }
    });

    try {
        expect(fn () => $this->publication->publish('replacement', cleanup: true))
            ->toThrow(RuntimeException::class, 'nested listener failed');
        expect($this->connection->transactionLevel())->toBe(1)
            ->and($this->metadata->stage('replacement')->value('state'))->toBe('published');
    } finally {
        Event::forget(TransactionCommitted::class);
        $this->connection->rollBack();
    }

    expect($this->reads->get(''))->toBe('original contents')
        ->and($this->metadata->stage('replacement')->value('state'))->toBe('ready')
        ->and($this->metadata->versions()->sole()->pending_delete)->toBe(0);
    $this->disk->assertExists(['event-publication/original', 'event-publication/replacement']);
    $this->cleanup->discard('replacement');
    $this->disk->assertMissing('event-publication/replacement');
    $this->disk->assertExists('event-publication/original');
});

it('keeps publication and rollback on the captured connection after the configured connection changes', function () {
    $this->connection->beginTransaction();
    $this->publication->publish('replacement', cleanup: true);
    config()->set('laravel-versioned-storage.connection', 'not-configured');

    try {
        $this->connection->rollBack();
        expect($this->reads->versions())->toBe(['original'])
            ->and($this->metadata->stage('replacement')->value('state'))->toBe('ready');
    } finally {
        config()->set('laravel-versioned-storage.connection', null);
    }
    $this->disk->assertExists('event-publication/replacement');
    $this->cleanup->discard('replacement');
    $this->disk->assertMissing('event-publication/replacement');
});

it('retains the committed current snapshot when an application after-commit callback throws', function () {
    $this->connection->beginTransaction();
    $this->connection->afterCommit(static fn () => throw new RuntimeException('after commit failed'));
    $this->publication->publish('replacement', cleanup: true);
    expect(fn () => $this->connection->commit())->toThrow(RuntimeException::class, 'after commit failed');
    $this->connection->beginTransaction();
    $this->connection->rollBack();

    expect($this->reads->get(''))->toBe('completed contents')
        ->and($this->reads->versions())->toBe(['replacement'])
        ->and($this->metadata->stage('replacement')->value('state'))->toBe('published')
        ->and($this->connection->getPdo()->inTransaction())->toBeFalse();
    $this->disk->assertExists(['event-publication/original', 'event-publication/replacement']);
    $this->cleanup->cleanup();
    $this->disk->assertMissing('event-publication/original');
    $this->disk->assertExists('event-publication/replacement');
});

it('retains a completed stage until explicit discard after an application after-rollback callback throws', function () {
    if (! method_exists($this->connection, 'afterRollBack')) {
        $this->markTestSkipped('This Laravel version does not support after-rollback callbacks.');
    }
    $this->connection->beginTransaction();
    $called = false;
    $this->connection->afterRollBack(static function () use (&$called): void {
        if (! $called) {
            $called = true;

            throw new RuntimeException('rollback callback failed');
        }
    });
    $this->publication->publish('replacement', cleanup: true);

    expect(fn () => $this->connection->rollBack())->toThrow(RuntimeException::class, 'rollback callback failed')
        ->and($called)->toBeTrue()
        ->and($this->connection->transactionLevel())->toBe(0)
        ->and($this->connection->getPdo()->inTransaction())->toBeFalse()
        ->and($this->reads->versions())->toBe(['original'])
        ->and($this->reads->get(''))->toBe('original contents')
        ->and($this->metadata->stage('replacement')->value('state'))->toBe('ready');
    $this->disk->assertExists(['event-publication/original', 'event-publication/replacement']);
    $this->cleanup->discard('replacement');
    $this->disk->assertMissing('event-publication/replacement');
    $this->disk->assertExists('event-publication/original');
});

it('rolls back publication and restores connection state after a committing listener throws', function () {
    Event::listen(TransactionCommitting::class, static fn () => throw new RuntimeException('before commit failed'));

    try {
        expect(fn () => $this->publication->publish('replacement', cleanup: true))
            ->toThrow(RuntimeException::class, 'before commit failed');
    } finally {
        Event::forget(TransactionCommitting::class);
    }

    expect($this->connection->transactionLevel())->toBe(0)
        ->and($this->connection->getPdo()->inTransaction())->toBeFalse()
        ->and($this->reads->get(''))->toBe('original contents')
        ->and($this->reads->versions())->toBe(['original'])
        ->and($this->metadata->stage('replacement')->value('state'))->toBe('ready')
        ->and($this->metadata->versions()->sole()->pending_delete)->toBe(0)
        ->and($this->disk->allFiles('event-publication'))->toBe(['event-publication/original', 'event-publication/replacement']);
    $this->cleanup->discard('replacement');
    $this->disk->assertMissing('event-publication/replacement');
});

it('preserves caller writes when a protected read cannot begin its savepoint', function () {
    $this->connection->beginTransaction();
    $this->connection->table('versioned_storage_reservations')->insert(['version' => 'caller-owned']);
    Event::listen(TransactionBeginning::class, static fn () => throw new RuntimeException('beginning failed'));

    try {
        expect(fn () => $this->reads->get(''))->toThrow(RuntimeException::class, 'beginning failed');
    } finally {
        Event::forget(TransactionBeginning::class);
    }
    expect($this->connection->transactionLevel())->toBe(1)
        ->and($this->connection->getPdo()->inTransaction())->toBeTrue()
        ->and($this->reads->get(''))->toBe('original contents');
    $this->connection->commit();
    expect($this->connection->table('versioned_storage_reservations')->pluck('version')->all())->toBe(['caller-owned']);
    $this->disk->assertExists(['event-publication/original', 'event-publication/replacement']);
});

it('preserves caller writes when an existence check cannot begin its savepoint', function () {
    $this->connection->beginTransaction();
    $this->connection->table('versioned_storage_reservations')->insert(['version' => 'caller-owned']);
    Event::listen(TransactionBeginning::class, static fn () => throw new RuntimeException('beginning failed'));

    try {
        expect(fn () => $this->reads->exists(''))->toThrow(RuntimeException::class, 'beginning failed');
    } finally {
        Event::forget(TransactionBeginning::class);
    }
    expect($this->connection->transactionLevel())->toBe(1)
        ->and($this->connection->getPdo()->inTransaction())->toBeTrue()
        ->and($this->reads->exists(''))->toBeTrue();
    $this->connection->commit();
    expect($this->connection->table('versioned_storage_reservations')->pluck('version')->all())->toBe(['caller-owned']);
    $this->disk->assertExists(['event-publication/original', 'event-publication/replacement']);
});

it('preserves caller writes and a ready stage when publication cannot begin its savepoint', function () {
    $this->connection->beginTransaction();
    $this->connection->table('versioned_storage_reservations')->insert(['version' => 'caller-owned']);
    Event::listen(TransactionBeginning::class, static fn () => throw new RuntimeException('beginning failed'));

    try {
        expect(fn () => $this->publication->publish('replacement'))->toThrow(RuntimeException::class, 'beginning failed');
    } finally {
        Event::forget(TransactionBeginning::class);
    }
    expect($this->connection->transactionLevel())->toBe(1)
        ->and($this->connection->getPdo()->inTransaction())->toBeTrue()
        ->and($this->metadata->stage('replacement')->value('state'))->toBe('ready')
        ->and($this->reads->versions())->toBe(['original']);
    $this->connection->commit();
    expect($this->connection->table('versioned_storage_reservations')->pluck('version')->all())->toBe(['caller-owned']);
    $this->disk->assertExists(['event-publication/original', 'event-publication/replacement']);
});
