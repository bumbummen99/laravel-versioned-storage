<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

beforeEach(function () {
    $this->primary = DB::connection();
    $replica = new PDO('sqlite::memory:');
    $replica->exec('CREATE TABLE versioned_storage_resources (id INTEGER, key TEXT)');
    $replica->exec('CREATE TABLE versioned_storage_versions (id INTEGER, resource_id INTEGER, version TEXT, pending_delete INTEGER)');
    $this->primary->setReadPdo($replica);
});

afterEach(function () {
    $this->primary->setReadPdo($this->primary->getPdo());
});

it('lists authoritative versions rather than stale read replica metadata', function () {
    $version = VersionedStorage::put('replica', 'contents');

    expect(VersionedStorage::versions('replica'))->toBe([$version]);
});

it('discovers and retries pending deletions on the primary despite replica lag', function () {
    $first = VersionedStorage::put('retry-replica', 'original');
    $second = VersionedStorage::put('retry-replica', 'replacement');
    DB::table('versioned_storage_versions')->where('version', $first)->update(['pending_delete' => true]);

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    Storage::disk('local')->assertMissing("retry-replica/{$first}");
    expect(VersionedStorage::versions('retry-replica'))->toBe([$second]);
});

it('does not delete committed data based on a stale replica after a commit listener throws', function () {
    VersionedStorage::put('replica-failure', 'original');
    $stage = VersionedStorage::stage('replica-failure', 'committed');
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection->transactionLevel() === 0) {
            throw new RuntimeException('post-commit failure');
        }
    });

    try {
        expect(fn () => $stage->publish())->toThrow(RuntimeException::class, 'post-commit failure');
    } finally {
        Event::forget(TransactionCommitted::class);
    }

    expect(VersionedStorage::get('replica-failure'))->toBe('committed');
});
