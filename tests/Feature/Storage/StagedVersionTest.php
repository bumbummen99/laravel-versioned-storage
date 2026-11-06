<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('stages files and directories without publishing and can reload them by UUID', function (bool $directory) {
    $stage = $directory
        ? VersionedStorage::stageDirectory('staged', function (FilesystemAdapter $files): void {
            $files->put('one', 'contents');
        })
        : VersionedStorage::stage('staged', 'contents');
    expect($directory ? VersionedStorage::directory('staged')->versions() : VersionedStorage::versions('staged'))->toBe([]);
    Storage::disk('local')->assertExists('staged/'.$stage->version.($directory ? '/one' : ''));

    $this->travel(365)->days();
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('ready');
    DB::beginTransaction();
    expect(VersionedStorage::staged($stage->version)->publish())->toBe($stage->version);
    DB::commit();
    expect($directory ? VersionedStorage::directory('staged')->get('one') : VersionedStorage::get('staged'))->toBe('contents');
})->with([false, true]);

it('defaults to preserving older versions when a stage is published', function () {
    $first = VersionedStorage::put('staged', 'original');
    $stage = VersionedStorage::stage('staged', 'replacement');
    $stage->publish();
    expect(VersionedStorage::versions('staged'))->toBe([$first, $stage->version]);
});

it('commits upload intent before invoking a callback and marks completion only afterwards', function () {
    $stage = VersionedStorage::stageDirectory('staged', function (FilesystemAdapter $files): void {
        expect(DB::transactionLevel())->toBe(0)
            ->and(DB::table('versioned_storage_stages')->value('state'))->toBe('uploading')
            ->and(VersionedStorage::directory('staged')->versions())->toBe([]);
        $files->put('one', 'contents');
    });
    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('ready');
});

it('never writes files if recording the durable intent fails', function () {
    DB::statement("CREATE TRIGGER reject_stage BEFORE INSERT ON versioned_storage_stages BEGIN SELECT RAISE(FAIL, 'intent unavailable'); END");
    $called = false;

    try {
        expect(fn () => VersionedStorage::stageDirectory('staged', function () use (&$called): void {
            $called = true;
        }))->toThrow(QueryException::class, 'intent unavailable');
    } finally {
        DB::statement('DROP TRIGGER reject_stage');
    }
    expect($called)->toBeFalse()->and(DB::transactionLevel())->toBe(0);
    Storage::disk('local')->assertMissing('staged');
});

it('rejects raw PDO publication without rolling back application work', function () {
    $stage = VersionedStorage::stage('staged', 'contents');
    $pdo = DB::connection()->getPdo();
    $pdo->beginTransaction();

    try {
        expect(fn () => $stage->publish())->toThrow(RuntimeException::class, 'Laravel-managed')
            ->and($pdo->inTransaction())->toBeTrue();
    } finally {
        $pdo->rollBack();
    }
    expect($stage->publish())->toBe($stage->version);
});

it('makes publication retries idempotent without reviving or retiring later versions', function () {
    $stage = VersionedStorage::stage('staged', 'original');
    $stage->publish();
    $current = VersionedStorage::put('staged', 'replacement', cleanup: true);
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    $this->travel(365)->days();
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('published');
    expect(VersionedStorage::staged($stage->version)->publish(cleanup: true))->toBe($stage->version)
        ->and(VersionedStorage::versions('staged'))->toBe([$current])
        ->and(VersionedStorage::get('staged'))->toBe('replacement');
    Storage::disk('local')->assertMissing('staged/'.$stage->version);
});

it('rejects uploads inside Laravel or PDO transactions before any intent or filesystem write', function (bool $pdo, string $operation) {
    $connection = DB::connection();
    $called = false;
    $callback = function (FilesystemAdapter $files) use (&$called): void {
        $called = true;
        $files->put('one', 'contents');
    };

    if ($pdo) {
        $connection->getPdo()->beginTransaction();
    } else {
        $connection->beginTransaction();
    }

    try {
        expect(fn () => match ($operation) {
            'put' => VersionedStorage::put('staged', 'contents'),
            'directory' => VersionedStorage::directory('staged', $callback),
            'stage' => VersionedStorage::stage('staged', 'contents'),
            'stage-directory' => VersionedStorage::stageDirectory('staged', $callback),
        })->toThrow(RuntimeException::class, 'Stage first');
        expect($called)->toBeFalse()
            ->and($connection->table('versioned_storage_stages')->count())->toBe(0)
            ->and($connection->table('versioned_storage_resources')->count())->toBe(0);
        Storage::disk('local')->assertMissing('staged');
    } finally {
        if ($pdo) {
            $connection->getPdo()->rollBack();
        } else {
            $connection->rollBack();
        }
    }
})->with([false, true])->with(['put', 'directory', 'stage', 'stage-directory']);

it('discards an unpublished stage and makes its identifier permanently unpublishable', function () {
    $stage = VersionedStorage::stage('staged', 'contents');
    $stage->discard();
    $stage->discard();
    expect(fn () => $stage->publish())->toThrow(RuntimeException::class, 'does not exist or was discarded');
    expect(fn () => VersionedStorage::staged($stage->version))->toThrow(RuntimeException::class, 'does not exist or was discarded');
    Storage::disk('local')->assertMissing('staged/'.$stage->version);
    expect(DB::table('versioned_storage_reservations')->where('version', $stage->version)->exists())->toBeTrue();
});

it('never discards a published stage even after a later version retires it', function () {
    $stage = VersionedStorage::stage('staged', 'contents');
    $stage->publish();
    VersionedStorage::put('staged', 'replacement', cleanup: true);
    expect(fn () => $stage->discard())->toThrow(RuntimeException::class, 'cannot be discarded');
    Storage::disk('local')->assertExists('staged/'.$stage->version);
});

it('retains explicit discard failures for command retry', function () {
    $stage = VersionedStorage::stage('staged', 'contents');
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('delete')->once()->andThrow(new RuntimeException('deletion refused'));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, $disk->getConfig()));

    // Reload so the stage uses the adapter that simulates the outage.
    try {
        expect(fn () => VersionedStorage::staged($stage->version)->discard())->toThrow(RuntimeException::class, 'deletion refused');
    } finally {
        Storage::set('local', $disk);
    }
    expect(fn () => $stage->publish())->toThrow(RuntimeException::class, 'not ready');
    $discarding = DB::table('versioned_storage_stages')->where('version', $stage->version)->first();
    expect($discarding->state)->toBe('discarding')
        ->and(Carbon::parse($discarding->cleanup_after)->lessThanOrEqualTo(now()))->toBeTrue();
    DB::statement("CREATE TRIGGER reject_recovery BEFORE INSERT ON versioned_storage_versions WHEN NEW.pending_delete = 1 BEGIN SELECT RAISE(FAIL, 'recovery unavailable'); END");

    try {
        $this->artisan('laravel-versioned-storage:cleanup')->assertFailed();
        $disk->assertExists('staged/'.$stage->version);
        expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('discarding');
    } finally {
        DB::statement('DROP TRIGGER reject_recovery');
    }
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    Storage::disk('local')->assertMissing('staged/'.$stage->version);
});

it('aborts before writing if cleanup claims the committed intent before the writer locks it', function () {
    $claimed = false;
    $called = false;
    Event::listen(TransactionCommitted::class, function () use (&$claimed): void {
        if ($claimed || ! DB::table('versioned_storage_stages')->where('state', 'uploading')->exists()) {
            return;
        }
        $claimed = true;
        $this->travelTo(Carbon::parse(DB::table('versioned_storage_stages')->where('state', 'uploading')->value('cleanup_after')));
        VersionedStorage::directory('staged')->cleanup();
    });

    try {
        expect(fn () => VersionedStorage::stageDirectory('staged', function () use (&$called): void {
            $called = true;
        }))->toThrow(RuntimeException::class, 'reclaimed');
    } finally {
        Event::forget(TransactionCommitted::class);
    }
    expect($claimed)->toBeTrue()->and($called)->toBeFalse();
    Storage::disk('local')->assertMissing('staged');
});
