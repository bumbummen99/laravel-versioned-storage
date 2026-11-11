<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Actions\PublishStage;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

beforeEach(function (): void {
    $this->connection = DB::connection();
    $existing = new ResourceIdentity('existing', 'local', false);
    $new = new ResourceIdentity('new', 'local', true);
    $this->existingId = $this->connection->table('versioned_storage_resources')->insertGetId([
        'key' => $existing->key, 'disk' => 'local', 'path' => $existing->path,
        'is_directory' => false, 'current_version' => 'original',
    ]);
    $this->newId = $this->connection->table('versioned_storage_resources')->insertGetId([
        'key' => $new->key, 'disk' => 'local', 'path' => $new->path,
        'is_directory' => true, 'current_version' => null,
    ]);
    $this->connection->table('versioned_storage_versions')->insert([
        'resource_id' => $this->existingId, 'version' => 'original', 'created_at' => now(),
    ]);
    $this->connection->table('versioned_storage_stages')->insert([
        ['resource_id' => $this->existingId, 'version' => 'replacement', 'state' => 'ready'],
        ['resource_id' => $this->newId, 'version' => 'first-version', 'state' => 'ready'],
    ]);
    $this->connection->table('versioned_storage_reservations')->insert([
        ['version' => 'replacement'], ['version' => 'first-version'],
    ]);
    $this->existingMetadata = new ResourceMetadata($this->connection, $existing);
    $this->newMetadata = new ResourceMetadata($this->connection, $new);
    $this->existingPublication = new PublishStage($this->connection, $existing, $this->existingMetadata);
    $this->newPublication = new PublishStage($this->connection, $new, $this->newMetadata);
    $this->disk = Storage::disk('local');
    $this->disk->put('existing/original', 'original bytes');
    $this->disk->put('existing/replacement', 'replacement bytes');
    $this->disk->put('new/first-version/one', 'completed directory bytes');
});

it('rolls back publication and retirement for both a new and an established resource', function () {
    $this->connection->beginTransaction();
    $this->existingPublication->publish('replacement', cleanup: true);
    $this->newPublication->publish('first-version', cleanup: true);
    expect($this->existingMetadata->lockResource()->current_version)->toBe('replacement')
        ->and($this->newMetadata->lockResource()->current_version)->toBe('first-version')
        ->and($this->existingMetadata->versions()->where('version', 'original')->value('pending_delete'))->toBe(1);
    $this->connection->rollBack();

    expect($this->existingMetadata->lockResource()->current_version)->toBe('original')
        ->and($this->newMetadata->lockResource()->current_version)->toBeNull()
        ->and($this->existingMetadata->stage('replacement')->value('state'))->toBe('ready')
        ->and($this->newMetadata->stage('first-version')->value('state'))->toBe('ready')
        ->and($this->connection->table('versioned_storage_versions')->pluck('version')->all())->toBe(['original'])
        ->and($this->existingMetadata->versions()->sole()->pending_delete)->toBe(0)
        ->and($this->connection->table('versioned_storage_reservations')->count())->toBe(2);
    expect($this->disk->get('existing/original'))->toBe('original bytes')
        ->and($this->disk->get('existing/replacement'))->toBe('replacement bytes')
        ->and($this->disk->get('new/first-version/one'))->toBe('completed directory bytes');
});

it('keeps publication rolled back when unrelated outer work subsequently commits', function () {
    $this->connection->beginTransaction();
    $this->connection->table('versioned_storage_reservations')->insert(['version' => 'caller-work']);
    $this->connection->beginTransaction();
    $this->existingPublication->publish('replacement', cleanup: true);
    $this->newPublication->publish('first-version', cleanup: true);
    $this->connection->rollBack();
    $this->connection->commit();

    expect($this->existingMetadata->lockResource()->current_version)->toBe('original')
        ->and($this->newMetadata->lockResource()->current_version)->toBeNull()
        ->and($this->existingMetadata->stage('replacement')->value('state'))->toBe('ready')
        ->and($this->newMetadata->stage('first-version')->value('state'))->toBe('ready')
        ->and($this->connection->table('versioned_storage_versions')->pluck('version')->all())->toBe(['original'])
        ->and($this->existingMetadata->versions()->sole()->pending_delete)->toBe(0)
        ->and($this->connection->table('versioned_storage_reservations')->where('version', 'caller-work')->exists())->toBeTrue();
    expect($this->disk->get('existing/original'))->toBe('original bytes')
        ->and($this->disk->get('existing/replacement'))->toBe('replacement bytes')
        ->and($this->disk->get('new/first-version/one'))->toBe('completed directory bytes');
});

it('keeps completed stages and their reservations when both inner and outer transactions roll back', function () {
    $this->connection->beginTransaction();
    $this->connection->table('versioned_storage_reservations')->insert(['version' => 'caller-work']);
    $this->connection->beginTransaction();
    $this->existingPublication->publish('replacement', cleanup: true);
    $this->newPublication->publish('first-version', cleanup: true);
    $this->connection->rollBack();
    $this->connection->rollBack();

    expect($this->existingMetadata->lockResource()->current_version)->toBe('original')
        ->and($this->newMetadata->lockResource()->current_version)->toBeNull()
        ->and($this->existingMetadata->stage('replacement')->value('state'))->toBe('ready')
        ->and($this->newMetadata->stage('first-version')->value('state'))->toBe('ready')
        ->and($this->connection->table('versioned_storage_versions')->pluck('version')->all())->toBe(['original'])
        ->and($this->existingMetadata->versions()->sole()->pending_delete)->toBe(0)
        ->and($this->connection->table('versioned_storage_reservations')->orderBy('version')->pluck('version')->all())
        ->toBe(['first-version', 'replacement']);
    expect($this->disk->get('existing/original'))->toBe('original bytes')
        ->and($this->disk->get('existing/replacement'))->toBe('replacement bytes')
        ->and($this->disk->get('new/first-version/one'))->toBe('completed directory bytes');
});

it('does not partially publish when the final stage update fails', function () {
    $this->connection->statement("CREATE TRIGGER reject_published BEFORE UPDATE ON versioned_storage_stages WHEN NEW.state = 'published' BEGIN SELECT RAISE(FAIL, 'publication unavailable'); END");

    try {
        expect(fn () => $this->existingPublication->publish('replacement', cleanup: true))
            ->toThrow(QueryException::class, 'publication unavailable');
    } finally {
        $this->connection->statement('DROP TRIGGER reject_published');
    }

    expect($this->existingMetadata->lockResource()->current_version)->toBe('original')
        ->and($this->existingMetadata->stage('replacement')->value('state'))->toBe('ready')
        ->and($this->existingMetadata->versions()->sole()->version)->toBe('original')
        ->and($this->existingMetadata->versions()->sole()->pending_delete)->toBe(0)
        ->and($this->connection->transactionLevel())->toBe(0);
});

it('does not repoint a retired published stage or retire the later current version on retry', function () {
    $this->existingPublication->publish('replacement', cleanup: true);
    $this->connection->table('versioned_storage_stages')->insert([
        'resource_id' => $this->existingId, 'version' => 'later', 'state' => 'ready',
    ]);
    $this->existingPublication->publish('later', cleanup: true);

    $this->existingPublication->publish('replacement', cleanup: true);

    expect($this->existingMetadata->lockResource()->current_version)->toBe('later')
        ->and($this->existingMetadata->versions()->where('version', 'replacement')->value('pending_delete'))->toBe(1)
        ->and($this->existingMetadata->versions()->where('version', 'later')->value('pending_delete'))->toBe(0)
        ->and($this->existingMetadata->stage('replacement')->value('state'))->toBe('published')
        ->and($this->existingMetadata->versions()->count())->toBe(3);
});
