<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

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

it('keeps publication committed when a transaction committed listener throws and retries idempotently', function () {
    $first = VersionedStorage::put('committed', 'original');
    $stage = VersionedStorage::stage('committed', 'replacement');
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection->transactionLevel() === 0) {
            throw new RuntimeException('listener failed');
        }
    });

    try {
        expect(fn () => $stage->publish())
            ->toThrow(RuntimeException::class, 'listener failed');
    } finally {
        Event::forget(TransactionCommitted::class);
    }

    expect(VersionedStorage::get('committed'))->toBe('replacement')
        ->and(VersionedStorage::versions('committed'))->toHaveCount(2);
    $later = VersionedStorage::put('committed', 'later');
    expect($stage->publish())->toBe($stage->version)
        ->and(VersionedStorage::get('committed'))->toBe('later')
        ->and(VersionedStorage::versions('committed'))->toBe([$first, $stage->version, $later]);
});

it('restores a completed stage when a nested publication listener throws and the outer transaction rolls back', function () {
    $first = VersionedStorage::put('nested-listener', 'original');
    $stage = VersionedStorage::stage('nested-listener', 'replacement');
    DB::beginTransaction();
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection->transactionLevel() === 1) {
            throw new RuntimeException('nested listener failed');
        }
    });

    try {
        expect(fn () => $stage->publish(cleanup: true))
            ->toThrow(RuntimeException::class, 'nested listener failed');
    } finally {
        Event::forget(TransactionCommitted::class);
        DB::rollBack();
    }

    $files = Storage::disk('local')->allFiles('nested-listener');
    sort($files);
    $expectedFiles = ["nested-listener/{$first}", "nested-listener/{$stage->version}"];
    sort($expectedFiles);

    expect(VersionedStorage::get('nested-listener'))->toBe('original')
        ->and($files)->toBe($expectedFiles)
        ->and(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('ready');
    $stage->discard();
});

it('keeps rollback handling bound to the original metadata connection', function () {
    $first = VersionedStorage::put('connection-change', 'original');
    $stage = VersionedStorage::stage('connection-change', 'replacement');
    DB::beginTransaction();
    $second = $stage->publish(cleanup: true);
    config()->set('laravel-versioned-storage.connection', 'not-configured');

    try {
        DB::rollBack();
    } finally {
        config()->set('laravel-versioned-storage.connection', null);
    }

    expect(VersionedStorage::versions('connection-change'))->toBe([$first]);
    Storage::disk('local')->assertExists("connection-change/{$second}");
    $stage->discard();
});

it('does not delete a committed version when an after commit callback throws', function () {
    $first = VersionedStorage::put('after-commit', 'original');
    $stage = VersionedStorage::stage('after-commit', 'contents');
    DB::beginTransaction();
    DB::afterCommit(fn () => throw new RuntimeException('after commit failed'));
    $version = $stage->publish(cleanup: true);
    expect(fn () => DB::commit())->toThrow(RuntimeException::class, 'after commit failed');
    Storage::disk('local')->assertExists("after-commit/{$first}");
    DB::beginTransaction();
    DB::rollBack();

    expect(VersionedStorage::get('after-commit'))->toBe('contents')
        ->and(VersionedStorage::versions('after-commit'))->toBe([$version]);
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    Storage::disk('local')->assertMissing("after-commit/{$first}");
});

it('removes staged files when an application after rollback callback throws', function () {
    $connection = DB::connection();

    if (! method_exists($connection, 'afterRollBack')) {
        $this->markTestSkipped('This Laravel version does not support after-rollback callbacks.');
    }

    $first = VersionedStorage::put('rollback-callback', 'original');
    $stage = VersionedStorage::stage('rollback-callback', 'staged');
    $connection->beginTransaction();
    $callbackCalled = false;
    $connection->afterRollBack(function () use (&$callbackCalled): void {
        if (! $callbackCalled) {
            $callbackCalled = true;

            throw new RuntimeException('rollback callback failed');
        }
    });
    $second = $stage->publish(cleanup: true);

    expect(fn () => $connection->rollBack())
        ->toThrow(RuntimeException::class, 'rollback callback failed');

    expect($callbackCalled)->toBeTrue()
        ->and($connection->transactionLevel())->toBe(0)
        ->and($connection->getPdo()->inTransaction())->toBeFalse()
        ->and(VersionedStorage::versions('rollback-callback'))->toBe([$first])
        ->and(VersionedStorage::get('rollback-callback'))->toBe('original');
    Storage::disk('local')->assertExists("rollback-callback/{$first}");
    Storage::disk('local')->assertExists("rollback-callback/{$second}");
    $stage->discard();
});

it('restores transaction state and retains a completed stage when a before commit listener throws', function () {
    $first = VersionedStorage::put('precommit-failure', 'original');
    $stage = VersionedStorage::stage('precommit-failure', 'staged');
    $connection = DB::connection();
    Event::listen(TransactionCommitting::class, function (): void {
        throw new RuntimeException('before commit failed');
    });

    try {
        expect(fn () => $stage->publish(cleanup: true))
            ->toThrow(RuntimeException::class, 'before commit failed');

        $state = [
            'laravel_level' => $connection->transactionLevel(),
            'pdo_in_transaction' => $connection->getPdo()->inTransaction(),
            'files' => Storage::disk('local')->allFiles('precommit-failure'),
        ];
        sort($state['files']);
    } finally {
        Event::forget(TransactionCommitting::class);

        // Repair the connection only after capturing the state left by publication.
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        } elseif ($connection->getPdo()->inTransaction()) {
            $connection->getPdo()->rollBack();
        }
    }

    expect(VersionedStorage::versions('precommit-failure'))->toBe([$first])
        ->and(VersionedStorage::get('precommit-failure'))->toBe('original')
        ->and($state)->toBe([
            'laravel_level' => 0,
            'pdo_in_transaction' => false,
            'files' => collect(["precommit-failure/{$first}", "precommit-failure/{$stage->version}"])->sort()->values()->all(),
        ]);
    $stage->discard();
});

it('restores the original transaction depth when a transaction beginning listener throws', function (bool $outerTransaction, string $operation) {
    $connection = DB::connection();
    $first = VersionedStorage::put('beginning-failure', 'original');
    $stage = VersionedStorage::stage('beginning-failure', 'unpublished');
    $outerStage = $outerTransaction ? VersionedStorage::stage('outer-work', 'uncommitted contents') : null;
    $outerVersion = null;

    if ($outerTransaction) {
        $connection->beginTransaction();
        $outerVersion = $outerStage->publish();
    }

    $initialLevel = $connection->transactionLevel();
    Event::listen(TransactionBeginning::class, function (): void {
        throw new RuntimeException('beginning listener failed');
    });

    try {
        expect(fn () => match ($operation) {
            'read' => VersionedStorage::get('beginning-failure'),
            'exists' => VersionedStorage::exists('beginning-failure'),
            'write' => $stage->publish(),
        })->toThrow(RuntimeException::class, 'beginning listener failed');

        $state = [
            'level' => $connection->transactionLevel(),
            'pdo_in_transaction' => $connection->getPdo()->inTransaction(),
        ];
    } finally {
        Event::forget(TransactionBeginning::class);

        if ($connection->transactionLevel() > $initialLevel) {
            $connection->rollBack($initialLevel);
        }
    }

    try {
        expect($state)->toBe([
            'level' => $initialLevel,
            'pdo_in_transaction' => $outerTransaction,
        ]);
        $files = Storage::disk('local')->allFiles('beginning-failure');
        sort($files);
        $expectedFiles = ["beginning-failure/{$first}", "beginning-failure/{$stage->version}"];
        sort($expectedFiles);

        expect(VersionedStorage::versions('beginning-failure'))->toBe([$first])
            ->and(VersionedStorage::get('beginning-failure'))->toBe('original')
            ->and($files)->toBe($expectedFiles);

        if ($outerTransaction) {
            expect(VersionedStorage::get('outer-work', $outerVersion))->toBe('uncommitted contents');
            $connection->commit();
            expect(VersionedStorage::get('outer-work', $outerVersion))->toBe('uncommitted contents');
        }
        $stage->discard();
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }
    }
})->with([false, true])->with(['read', 'exists', 'write']);

it('rejects uploads inside an active metadata transaction before writing files', function () {
    DB::beginTransaction();
    $callbackCalled = false;

    try {
        expect(fn () => VersionedStorage::put('transaction-guard', 'contents'))
            ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction')
            ->and(fn () => VersionedStorage::stage('transaction-guard', 'contents'))
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

it('retains a rolled back directory stage without deleting the original snapshot', function () {
    $first = VersionedStorage::directory('directory', function (FilesystemAdapter $files): void {
        $files->put('one', 'original');
    });
    $stage = VersionedStorage::stageDirectory('directory', function (FilesystemAdapter $files): void {
        $files->put('one', 'replacement');
    });
    DB::beginTransaction();
    $second = $stage->publish(cleanup: true);
    DB::rollBack();

    expect(VersionedStorage::directory('directory')->get('one'))->toBe('original')
        ->and(VersionedStorage::directory('directory')->versions())->toBe([$first])
        ->and(DB::table('versioned_storage_stages')->where('version', $second)->value('state'))->toBe('ready');
    Storage::disk('local')->assertExists("directory/{$second}/one");
    $stage->discard();
});

it('retains completed stages from multiple rolled back publications until explicit discard', function () {
    $disk = Storage::disk('local');
    $first = VersionedStorage::stage('rollback-one', 'one');
    $second = VersionedStorage::stage('rollback-two', 'two');
    DB::beginTransaction();
    DB::beginTransaction();
    $first->publish();
    $second->publish();
    DB::rollBack();
    DB::commit();

    expect(DB::transactionLevel())->toBe(0)
        ->and(VersionedStorage::versions('rollback-one'))->toBe([])
        ->and(VersionedStorage::versions('rollback-two'))->toBe([])
        ->and(DB::table('versioned_storage_stages')->whereIn('version', [$first->version, $second->version])->where('state', 'ready')->count())->toBe(2);
    $disk->assertExists(["rollback-one/{$first->version}", "rollback-two/{$second->version}"]);
    $first->discard();
    $second->discard();
    $disk->assertMissing(["rollback-one/{$first->version}", "rollback-two/{$second->version}"]);
});
