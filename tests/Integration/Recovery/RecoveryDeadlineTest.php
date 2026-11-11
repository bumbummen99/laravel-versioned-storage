<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;

it('retains a real unacknowledged upload until its deadline before allowing explicit discard', function () {
    $this->freezeTime();
    DB::statement("CREATE TRIGGER reject_acknowledgement BEFORE DELETE ON versioned_storage_operations BEGIN SELECT RAISE(FAIL, 'acknowledgement unavailable'); END");

    try {
        expect(fn () => VersionedStorage::put('deadline', 'contents'))
            ->toThrow(QueryException::class, 'acknowledgement unavailable');
    } finally {
        DB::statement('DROP TRIGGER reject_acknowledgement');
    }

    $journal = DB::table('versioned_storage_operations')->sole();
    $handler = new VersionedStorageHandler('deadline', 'local', false);
    $this->travelTo(Carbon::parse($journal->cleanup_after)->subSecond());

    expect(fn () => $handler->discardStage($journal->version))
        ->toThrow(RuntimeException::class, 'must retain its journal until the cleanup deadline')
        ->and(DB::table('versioned_storage_operations')->where('version', $journal->version)->exists())->toBeTrue()
        ->and(DB::table('versioned_storage_stages')->where('version', $journal->version)->value('state'))->toBe('uploading');
    Storage::disk('local')->assertExists("deadline/{$journal->version}");

    $this->travelTo(Carbon::parse($journal->cleanup_after));
    $handler->discardStage($journal->version);

    expect(DB::table('versioned_storage_operations')->where('version', $journal->version)->exists())->toBeFalse()
        ->and(DB::table('versioned_storage_stages')->where('version', $journal->version)->exists())->toBeFalse()
        ->and(VersionedStorage::versions('deadline'))->toBe([]);
    Storage::disk('local')->assertMissing("deadline/{$journal->version}");
});

it('recovers a real unacknowledged upload without treating absent acknowledgement as a stopped writer', function () {
    $this->freezeTime();
    DB::statement("CREATE TRIGGER reject_acknowledgement BEFORE DELETE ON versioned_storage_operations BEGIN SELECT RAISE(FAIL, 'acknowledgement unavailable'); END");

    try {
        expect(fn () => VersionedStorage::put('deadline', 'contents'))
            ->toThrow(QueryException::class, 'acknowledgement unavailable');
    } finally {
        DB::statement('DROP TRIGGER reject_acknowledgement');
    }

    $journal = DB::table('versioned_storage_operations')->sole();
    $handler = new VersionedStorageHandler('deadline', 'local', false);
    $this->travelTo(Carbon::parse($journal->cleanup_after)->subSecond());
    $handler->cleanup();

    expect(DB::table('versioned_storage_operations')->where('version', $journal->version)->exists())->toBeTrue()
        ->and(DB::table('versioned_storage_stages')->where('version', $journal->version)->value('state'))->toBe('uploading');
    Storage::disk('local')->assertExists("deadline/{$journal->version}");

    $this->travelTo(Carbon::parse($journal->cleanup_after));
    $handler->cleanup();

    expect(DB::table('versioned_storage_operations')->where('version', $journal->version)->exists())->toBeFalse()
        ->and(DB::table('versioned_storage_stages')->where('version', $journal->version)->exists())->toBeFalse()
        ->and(VersionedStorage::versions('deadline'))->toBe([]);
    Storage::disk('local')->assertMissing("deadline/{$journal->version}");
});
