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
