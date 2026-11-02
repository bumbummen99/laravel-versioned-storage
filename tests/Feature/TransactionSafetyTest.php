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

it('defers cleanup until the outer commit and removes staged files on rollback', function () {
    $first = VersionedStorage::put('rollback', 'original');
    DB::beginTransaction();
    $second = VersionedStorage::put('rollback', 'replacement', true);
    Storage::disk('local')->assertExists("rollback/{$first}");
    DB::rollBack();

    expect(VersionedStorage::get('rollback'))->toBe('original')
        ->and(VersionedStorage::versions('rollback'))->toBe([$first]);
    Storage::disk('local')->assertMissing("rollback/{$second}");

    DB::transaction(function () use ($first): void {
        VersionedStorage::put('rollback', 'committed', true);
        Storage::disk('local')->assertExists("rollback/{$first}");
    });

    expect(VersionedStorage::get('rollback'))->toBe('committed');
    Storage::disk('local')->assertMissing("rollback/{$first}");
});

it('tracks nested savepoint commits and rollbacks at their correct level', function () {
    $first = VersionedStorage::put('nested', 'original');
    DB::beginTransaction();
    $second = VersionedStorage::put('nested', 'outer', true);
    DB::beginTransaction();
    $third = VersionedStorage::put('nested', 'inner', true);
    DB::rollBack();
    Storage::disk('local')->assertMissing("nested/{$third}");
    expect(VersionedStorage::get('nested'))->toBe('outer');
    DB::beginTransaction();
    $fourth = VersionedStorage::put('nested', 'inner committed', true);
    DB::commit();
    DB::rollBack();

    expect(VersionedStorage::get('nested'))->toBe('original')
        ->and(VersionedStorage::versions('nested'))->toBe([$first]);
    Storage::disk('local')->assertMissing(["nested/{$second}", "nested/{$fourth}"]);
});

it('does not delete a committed version when a transaction committed listener throws', function () {
    VersionedStorage::put('committed', 'original');
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection->transactionLevel() === 0) {
            throw new RuntimeException('listener failed');
        }
    });

    try {
        expect(fn () => VersionedStorage::put('committed', 'replacement'))
            ->toThrow(RuntimeException::class, 'listener failed');
    } finally {
        Event::forget(TransactionCommitted::class);
    }

    expect(VersionedStorage::get('committed'))->toBe('replacement')
        ->and(VersionedStorage::versions('committed'))->toHaveCount(2);
});

it('cleans staged versions when a nested commit listener throws and the outer transaction rolls back', function () {
    $first = VersionedStorage::put('nested-listener', 'original');
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection->transactionLevel() === 1) {
            throw new RuntimeException('nested listener failed');
        }
    });

    try {
        expect(fn () => DB::transaction(fn () => VersionedStorage::put('nested-listener', 'replacement', true)))
            ->toThrow(RuntimeException::class, 'nested listener failed');
    } finally {
        Event::forget(TransactionCommitted::class);
    }

    expect(VersionedStorage::get('nested-listener'))->toBe('original')
        ->and(Storage::disk('local')->allFiles('nested-listener'))->toBe(["nested-listener/{$first}"]);
});

it('keeps rollback handling bound to the original metadata connection', function () {
    $first = VersionedStorage::put('connection-change', 'original');
    DB::beginTransaction();
    $second = VersionedStorage::put('connection-change', 'replacement', true);
    config()->set('laravel-versioned-storage.connection', 'not-configured');

    try {
        DB::rollBack();
    } finally {
        config()->set('laravel-versioned-storage.connection', null);
    }

    expect(VersionedStorage::versions('connection-change'))->toBe([$first]);
    Storage::disk('local')->assertMissing("connection-change/{$second}");
});

it('does not delete a committed version when an after commit callback throws', function () {
    $first = VersionedStorage::put('after-commit', 'original');
    DB::beginTransaction();
    DB::afterCommit(fn () => throw new RuntimeException('after commit failed'));
    $version = VersionedStorage::put('after-commit', 'contents', true);
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
    $connection->beginTransaction();
    $callbackCalled = false;
    $connection->afterRollBack(function () use (&$callbackCalled): void {
        if (! $callbackCalled) {
            $callbackCalled = true;

            throw new RuntimeException('rollback callback failed');
        }
    });
    $second = VersionedStorage::put('rollback-callback', 'staged', true);

    expect(fn () => $connection->rollBack())
        ->toThrow(RuntimeException::class, 'rollback callback failed');

    expect($callbackCalled)->toBeTrue()
        ->and($connection->transactionLevel())->toBe(0)
        ->and($connection->getPdo()->inTransaction())->toBeFalse()
        ->and(VersionedStorage::versions('rollback-callback'))->toBe([$first])
        ->and(VersionedStorage::get('rollback-callback'))->toBe('original');
    Storage::disk('local')->assertExists("rollback-callback/{$first}");
    Storage::disk('local')->assertMissing("rollback-callback/{$second}");
});

it('restores transaction state and removes staged files when a before commit listener throws', function () {
    $first = VersionedStorage::put('precommit-failure', 'original');
    $connection = DB::connection();
    Event::listen(TransactionCommitting::class, function (): void {
        throw new RuntimeException('before commit failed');
    });

    try {
        expect(fn () => VersionedStorage::put('precommit-failure', 'staged', true))
            ->toThrow(RuntimeException::class, 'before commit failed');

        $state = [
            'laravel_level' => $connection->transactionLevel(),
            'pdo_in_transaction' => $connection->getPdo()->inTransaction(),
            'files' => Storage::disk('local')->allFiles('precommit-failure'),
        ];
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
            'files' => ["precommit-failure/{$first}"],
        ]);
});

it('restores the original transaction depth when a transaction beginning listener throws', function (bool $outerTransaction, string $operation) {
    $connection = DB::connection();
    $first = VersionedStorage::put('beginning-failure', 'original');
    $outerVersion = null;

    if ($outerTransaction) {
        $connection->beginTransaction();
        $outerVersion = VersionedStorage::put('outer-work', 'uncommitted contents');
    }

    $initialLevel = $connection->transactionLevel();
    Event::listen(TransactionBeginning::class, function (): void {
        throw new RuntimeException('beginning listener failed');
    });

    try {
        expect(fn () => match ($operation) {
            'read' => VersionedStorage::get('beginning-failure'),
            'exists' => VersionedStorage::exists('beginning-failure'),
            'write' => VersionedStorage::put('beginning-failure', 'unpublished', true),
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
        expect(VersionedStorage::versions('beginning-failure'))->toBe([$first])
            ->and(VersionedStorage::get('beginning-failure'))->toBe('original')
            ->and(Storage::disk('local')->allFiles('beginning-failure'))->toBe(["beginning-failure/{$first}"]);

        if ($outerTransaction) {
            expect(VersionedStorage::get('outer-work', $outerVersion))->toBe('uncommitted contents');
            $connection->commit();
            expect(VersionedStorage::get('outer-work', $outerVersion))->toBe('uncommitted contents');
        }
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }
    }
})->with([false, true])->with(['read', 'exists', 'write']);

it('retains failed cleanup records and can retry cleanup without publishing again', function () {
    $first = VersionedStorage::put('retry', 'original');
    $second = VersionedStorage::put('retry', 'middle');
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with("retry/{$first}")
        ->andThrow(UnableToDeleteFile::atLocation("retry/{$first}"));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));

    expect(fn () => VersionedStorage::put('retry', 'replacement', true))->toThrow(RuntimeException::class);
    expect(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeTrue();
    Storage::set('local', $disk);
    VersionedStorage::cleanup('retry');

    Storage::disk('local')->assertMissing(["retry/{$first}", "retry/{$second}"]);
    expect(VersionedStorage::get('retry'))->toBe('replacement')
        ->and(VersionedStorage::versions('retry'))->toHaveCount(1)
        ->and(DB::table('versioned_storage_versions')->count())->toBe(1);
});

it('preserves the committed version when deferred cleanup fails at the outer commit', function () {
    $first = VersionedStorage::put('deferred-failure', 'original');
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->with("deferred-failure/{$first}")
        ->andThrow(UnableToDeleteFile::atLocation("deferred-failure/{$first}"));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));

    DB::beginTransaction();
    $second = VersionedStorage::put('deferred-failure', 'replacement', true);
    Storage::disk('local')->assertExists("deferred-failure/{$first}");
    expect(fn () => DB::commit())->toThrow(RuntimeException::class, 'Unable to remove version')
        ->and(VersionedStorage::get('deferred-failure'))->toBe('replacement')
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

it('removes rolled back directory snapshots without deleting the original snapshot', function () {
    $first = VersionedStorage::directory('directory', function (FilesystemAdapter $files): void {
        $files->put('one', 'original');
    });
    DB::beginTransaction();
    $second = VersionedStorage::directory('directory', function (FilesystemAdapter $files): void {
        $files->put('one', 'replacement');
    }, true);
    DB::rollBack();

    expect(VersionedStorage::directory('directory')->get('one'))->toBe('original')
        ->and(VersionedStorage::directory('directory')->versions())->toBe([$first]);
    Storage::disk('local')->assertMissing("directory/{$second}/one");
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
