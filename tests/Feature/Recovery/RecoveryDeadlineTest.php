<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;

it('retains an uncertain snapshot before its deadline and permits deletion at or after it', function (string $action, int $offset) {
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
    $this->travelTo(Carbon::parse($journal->cleanup_after)->addSeconds($offset));

    if ($action === 'discard' && $offset < 0) {
        expect(fn () => $handler->discardStage($journal->version))
            ->toThrow(RuntimeException::class, 'must retain its journal until the cleanup deadline');
    } elseif ($action === 'discard') {
        $handler->discardStage($journal->version);
    } else {
        $handler->cleanup();
    }

    expect(DB::table('versioned_storage_operations')->where('version', $journal->version)->exists())->toBe($offset < 0)
        ->and(DB::table('versioned_storage_stages')->where('version', $journal->version)->exists())->toBe($offset < 0)
        ->and(Storage::disk('local')->exists("deadline/{$journal->version}"))->toBe($offset < 0)
        ->and(VersionedStorage::versions('deadline'))->toBe([]);
})->with(['discard', 'cleanup'])->with([
    'one second before' => -1,
    'exact deadline' => 0,
    'one second after' => 1,
]);
