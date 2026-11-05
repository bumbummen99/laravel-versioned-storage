<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToDeleteFile;
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

it('retains failed cleanup records and can retry cleanup without publishing again', function () {
    $first = VersionedStorage::put('retry', 'original');
    $second = VersionedStorage::put('retry', 'middle');
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with("retry/{$first}")
        ->andThrow(UnableToDeleteFile::atLocation("retry/{$first}"));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));

    VersionedStorage::put('retry', 'replacement', true);
    expect(fn () => VersionedStorage::cleanup('retry'))->toThrow(RuntimeException::class);
    expect(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeTrue();
    Storage::set('local', $disk);
    VersionedStorage::cleanup('retry');

    Storage::disk('local')->assertMissing(["retry/{$first}", "retry/{$second}"]);
    expect(VersionedStorage::get('retry'))->toBe('replacement')
        ->and(VersionedStorage::versions('retry'))->toHaveCount(1)
        ->and(DB::table('versioned_storage_versions')->count())->toBe(1);
});

it('preserves the committed version when command cleanup fails', function () {
    $first = VersionedStorage::put('deferred-failure', 'original');
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with("deferred-failure/{$first}")
        ->andThrow(UnableToDeleteFile::atLocation("deferred-failure/{$first}"));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));

    $stage = VersionedStorage::stage('deferred-failure', 'replacement');
    DB::beginTransaction();
    $second = $stage->publish(cleanup: true);
    Storage::disk('local')->assertExists("deferred-failure/{$first}");
    DB::commit();
    Storage::disk('local')->assertExists("deferred-failure/{$first}");
    $this->artisan('laravel-versioned-storage:cleanup')->assertFailed();
    expect(VersionedStorage::get('deferred-failure'))->toBe('replacement')
        ->and(VersionedStorage::versions('deferred-failure'))->toBe([$second])
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeTrue();

    Storage::set('local', $disk);
    VersionedStorage::cleanup('deferred-failure');
    Storage::disk('local')->assertMissing("deferred-failure/{$first}");
});

it('writes uploaded and local file contents to the exact version filename', function () {
    $upload = UploadedFile::fake()->createWithContent('example.txt', 'uploaded');
    $first = VersionedStorage::put('upload', $upload);
    $second = VersionedStorage::put('local-file', new File($upload->getPathname()));

    expect(Storage::disk('local')->fileExists("upload/{$first}"))->toBeTrue()
        ->and(VersionedStorage::get('upload'))->toBe('uploaded')
        ->and(Storage::disk('local')->fileExists("local-file/{$second}"))->toBeTrue()
        ->and(VersionedStorage::get('local-file'))->toBe('uploaded');
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

it('recovers failed discards of rolled back stages into durable cleanup work', function (bool $directory, bool $newResource, string $outer) {
    $versions = static fn (): array => $directory
        ? VersionedStorage::directory('rollback-retry')->versions()
        : VersionedStorage::versions('rollback-retry');
    $first = $newResource ? null : ($directory
        ? VersionedStorage::directory('rollback-retry', function (FilesystemAdapter $files): void {
            $files->put('one', 'original');
        })
        : VersionedStorage::put('rollback-retry', 'original'));
    $disk = Storage::disk('local');
    $refuseDeletion = false;
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $method = $directory ? 'deleteDirectory' : 'delete';
    $adapter->shouldReceive($method)->andReturnUsing(function (string $path) use (&$refuseDeletion, $disk, $method): void {
        if ($refuseDeletion) {
            throw new RuntimeException('snapshot deletion refused');
        }

        $disk->getAdapter()->$method($path);
    });
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));
    $stage = $directory
        ? VersionedStorage::stageDirectory('rollback-retry', function (FilesystemAdapter $files): void {
            $files->put('one', 'replacement');
        })
        : VersionedStorage::stage('rollback-retry', 'replacement');

    if ($outer !== 'none') {
        DB::beginTransaction();
    }

    DB::beginTransaction();
    $stage->publish(cleanup: true);
    DB::rollBack();

    if ($outer === 'commit') {
        DB::commit();
    } elseif ($outer === 'rollback') {
        DB::rollBack();
    }

    $expectedVersions = $first === null ? [] : [$first];
    expect(DB::transactionLevel())->toBe(0)
        ->and($versions())->toBe($expectedVersions)
        ->and(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('ready');
    $disk->assertExists($directory ? "rollback-retry/{$stage->version}/one" : "rollback-retry/{$stage->version}");
    $refuseDeletion = true;
    expect(fn () => $stage->discard())->toThrow(RuntimeException::class, 'snapshot deletion refused');
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('discarding');
    $this->artisan('laravel-versioned-storage:cleanup')
        ->expectsOutputToContain('Unable to remove version')
        ->assertFailed();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeFalse()
        ->and(DB::table('versioned_storage_versions')->where('version', $stage->version)->value('pending_delete'))->toBe(1);
    $disk->assertExists($directory ? "rollback-retry/{$stage->version}/one" : "rollback-retry/{$stage->version}");
    $refuseDeletion = false;
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeFalse()
        ->and(DB::table('versioned_storage_versions')->where('version', $stage->version)->exists())->toBeFalse()
        ->and($versions())->toBe($expectedVersions);
    $disk->assertMissing($directory ? "rollback-retry/{$stage->version}/one" : "rollback-retry/{$stage->version}");

    if ($first !== null) {
        expect($directory
            ? VersionedStorage::directory('rollback-retry')->get('one')
            : VersionedStorage::get('rollback-retry'))->toBe('original');
        $disk->assertExists("rollback-retry/{$first}");
    }
})->with([false, true])->with([false, true])->with(['none', 'commit', 'rollback']);

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

it('retries durable cleanup work left by an interrupted process', function () {
    $first = VersionedStorage::put('interrupted', 'original');
    $second = VersionedStorage::put('interrupted', 'replacement');
    DB::table('versioned_storage_versions')->where('version', $first)->update(['pending_delete' => true]);
    $directory = VersionedStorage::directory('directory', function (FilesystemAdapter $files): void {
        $files->put('one', 'original');
    });
    VersionedStorage::directory('directory', function (FilesystemAdapter $files): void {
        $files->put('two', 'replacement');
    });
    DB::table('versioned_storage_versions')->where('version', $directory)->update(['pending_delete' => true]);

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    Storage::disk('local')->assertMissing(["interrupted/{$first}", "directory/{$directory}/one"]);
    expect(VersionedStorage::get('interrupted'))->toBe('replacement')
        ->and(VersionedStorage::versions('interrupted'))->toBe([$second])
        ->and(VersionedStorage::directory('directory')->get('two'))->toBe('replacement');
});

it('continues cleanup of other resources and reports command failures for retry', function () {
    $first = VersionedStorage::put('failed', 'original');
    VersionedStorage::put('failed', 'replacement');
    $other = VersionedStorage::put('other', 'original');
    VersionedStorage::put('other', 'replacement');
    DB::table('versioned_storage_versions')->whereIn('version', [$first, $other])->update(['pending_delete' => true]);
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with("failed/{$first}")
        ->andThrow(UnableToDeleteFile::atLocation("failed/{$first}"));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));

    $this->artisan('laravel-versioned-storage:cleanup')
        ->expectsOutputToContain('Unable to remove version')
        ->assertFailed();
    Storage::disk('local')->assertMissing("other/{$other}");
    expect(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeTrue();
    Storage::set('local', $disk);
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
});
