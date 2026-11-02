<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('rolls staged files back on the disk that received the write even after its name is rebound', function () {
    $original = Storage::disk('local');
    $replacement = Storage::fake('replacement');
    DB::beginTransaction();
    $version = VersionedStorage::put('rebound', 'staged');
    $replacement->put("rebound/{$version}", 'unrelated');
    Storage::set('local', $replacement);
    DB::rollBack();

    expect($original->exists("rebound/{$version}"))->toBeFalse()
        ->and($replacement->get("rebound/{$version}"))->toBe('unrelated');
});

it('lists authoritative versions rather than stale read replica metadata', function () {
    $connection = DB::connection();
    $replica = new PDO('sqlite::memory:');
    $replica->exec('CREATE TABLE versioned_storage_resources (id INTEGER, key TEXT)');
    $replica->exec('CREATE TABLE versioned_storage_versions (id INTEGER, resource_id INTEGER, version TEXT, pending_delete INTEGER)');
    $connection->setReadPdo($replica);

    try {
        $version = VersionedStorage::put('replica', 'contents');
        expect(VersionedStorage::versions('replica'))->toBe([$version]);
    } finally {
        $connection->setReadPdo($connection->getPdo());
    }
});

it('cleans the original disk after commit even if the configured disk name is rebound', function () {
    $original = Storage::disk('local');
    $first = VersionedStorage::put('rebound-commit', 'original');
    $replacement = Storage::fake('replacement');
    $replacement->put("rebound-commit/{$first}", 'unrelated');
    DB::beginTransaction();
    $second = VersionedStorage::put('rebound-commit', 'replacement', true);
    Storage::set('local', $replacement);
    DB::commit();

    expect($original->exists("rebound-commit/{$first}"))->toBeFalse()
        ->and($original->get("rebound-commit/{$second}"))->toBe('replacement')
        ->and($replacement->get("rebound-commit/{$first}"))->toBe('unrelated');
});

it('discovers and retries pending deletions on the primary despite replica lag', function () {
    $first = VersionedStorage::put('retry-replica', 'original');
    $second = VersionedStorage::put('retry-replica', 'replacement');
    DB::table('versioned_storage_versions')->where('version', $first)->update(['pending_delete' => true]);
    $connection = DB::connection();
    $replica = new PDO('sqlite::memory:');
    $replica->exec('CREATE TABLE versioned_storage_resources (id INTEGER, key TEXT)');
    $replica->exec('CREATE TABLE versioned_storage_versions (id INTEGER, resource_id INTEGER, version TEXT, pending_delete INTEGER)');
    $connection->setReadPdo($replica);

    try {
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        Storage::disk('local')->assertMissing("retry-replica/{$first}");
        expect(VersionedStorage::versions('retry-replica'))->toBe([$second]);
    } finally {
        $connection->setReadPdo($connection->getPdo());
    }
});

it('does not delete committed data based on a stale replica after a commit listener throws', function () {
    $connection = DB::connection();
    $replica = new PDO('sqlite::memory:');
    $replica->exec('CREATE TABLE versioned_storage_resources (id INTEGER, key TEXT)');
    $replica->exec('CREATE TABLE versioned_storage_versions (id INTEGER, resource_id INTEGER, version TEXT, pending_delete INTEGER)');
    $connection->setReadPdo($replica);
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection->transactionLevel() === 0) {
            throw new RuntimeException('post-commit failure');
        }
    });

    try {
        expect(fn () => VersionedStorage::put('replica-failure', 'committed'))
            ->toThrow(RuntimeException::class, 'post-commit failure');
    } finally {
        Event::forget(TransactionCommitted::class);
        $connection->setReadPdo($connection->getPdo());
    }

    expect(VersionedStorage::get('replica-failure'))->toBe('committed');
});
