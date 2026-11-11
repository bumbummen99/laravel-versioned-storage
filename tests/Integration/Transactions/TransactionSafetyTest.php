<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Testing\VersionedStorageTesting;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;

it('refuses forks that would inherit an unrelated database transaction', function (bool $rawPdo) {
    config()->set('database.connections.unrelated', config('database.connections.testing'));
    $connection = DB::connection('unrelated');
    $handler = new VersionedStorageHandler('fork-transaction', 'local');
    $rawPdo ? $connection->getPdo()->beginTransaction() : $connection->beginTransaction();
    VersionedStorageTesting::restoreUploadExecution();

    try {
        expect(fn () => $handler->stage(function (): void {
            throw new LogicException('The upload callback must not execute.');
        }))->toThrow(RuntimeException::class, 'Forked uploads cannot inherit active database transactions');
        expect($connection->getPdo()->inTransaction())->toBeTrue()
            ->and($connection->transactionLevel())->toBe($rawPdo ? 0 : 1)
            ->and(DB::table('versioned_storage_stages')->count())->toBe(0);
        Storage::disk('local')->assertMissing('fork-transaction');
    } finally {
        $rawPdo ? $connection->getPdo()->rollBack() : $connection->rollBack();
        DB::purge('unrelated');
        config()->set('database.connections.unrelated', null);
        VersionedStorageTesting::runUploadsSynchronously();
    }
})->with(['Laravel' => false, 'PDO' => true]);

it('publishes pre-uploaded snapshots transactionally and retains rolled back stages', function () {
    $first = VersionedStorage::put('rollback', 'original');
    $rolledBack = VersionedStorage::stage('rollback', 'replacement');
    DB::beginTransaction();
    $second = $rolledBack->publish(cleanup: true);
    Storage::disk('local')->assertExists("rollback/{$first}");
    DB::rollBack();

    expect(VersionedStorage::get('rollback'))->toBe('original')
        ->and(VersionedStorage::versions('rollback'))->toBe([$first])
        ->and(DB::table('versioned_storage_stages')->where('version', $second)->value('state'))->toBe('ready');
    Storage::disk('local')->assertExists("rollback/{$second}");
    $rolledBack->discard();
    Storage::disk('local')->assertMissing("rollback/{$second}");

    $committed = VersionedStorage::stage('rollback', 'committed');
    DB::transaction(function () use ($first, $committed): void {
        $committed->publish(cleanup: true);
        Storage::disk('local')->assertExists("rollback/{$first}");
    });

    expect(VersionedStorage::get('rollback'))->toBe('committed');
    Storage::disk('local')->assertExists("rollback/{$first}");
    app()->terminate();
    Storage::disk('local')->assertExists("rollback/{$first}");
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    Storage::disk('local')->assertMissing("rollback/{$first}");
});

it('tracks nested savepoint publication rollbacks without deleting completed stages', function () {
    $first = VersionedStorage::put('nested', 'original');
    $outer = VersionedStorage::stage('nested', 'outer');
    $inner = VersionedStorage::stage('nested', 'inner');
    $innerCommitted = VersionedStorage::stage('nested', 'inner committed');
    DB::beginTransaction();
    $outer->publish(cleanup: true);
    DB::beginTransaction();
    $inner->publish(cleanup: true);
    DB::rollBack();
    Storage::disk('local')->assertExists("nested/{$inner->version}");
    expect(DB::table('versioned_storage_stages')->where('version', $inner->version)->value('state'))->toBe('ready');
    expect(VersionedStorage::get('nested'))->toBe('outer');
    DB::beginTransaction();
    $innerCommitted->publish(cleanup: true);
    DB::commit();
    DB::rollBack();

    expect(VersionedStorage::get('nested'))->toBe('original')
        ->and(VersionedStorage::versions('nested'))->toBe([$first]);
    foreach ([$outer, $inner, $innerCommitted] as $stage) {
        Storage::disk('local')->assertExists("nested/{$stage->version}");
        $stage->discard();
        Storage::disk('local')->assertMissing("nested/{$stage->version}");
    }
});

it('rejects uploads inside an active metadata transaction before writing files', function () {
    DB::beginTransaction();
    $callbackCalled = false;

    try {
        expect(fn () => VersionedStorage::put('transaction-guard', 'contents'))
            ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction')
            ->and(fn () => VersionedStorage::stage('transaction-guard', 'contents'))
            ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction')
            ->and(fn () => VersionedStorage::stageDirectory('transaction-guard', function () use (&$callbackCalled): void {
                $callbackCalled = true;
            }))
            ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction')
            ->and(fn () => VersionedStorage::directory('transaction-guard', function () use (&$callbackCalled): void {
                $callbackCalled = true;
            }))
            ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction');
        expect(DB::transactionLevel())->toBe(1)
            ->and($callbackCalled)->toBeFalse()
            ->and(Storage::disk('local')->allFiles('transaction-guard'))->toBe([])
            ->and(DB::table('versioned_storage_versions')->count())->toBe(0);
    } finally {
        DB::rollBack();
    }
});
