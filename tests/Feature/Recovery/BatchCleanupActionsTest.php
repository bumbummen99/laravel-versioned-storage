<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Actions\CleanupStorage;
use SkyRaptor\LaravelVersionedStorage\Actions\ReconcileDeletions;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('continues batch deletion after recovery errors and supports independent resource recovery', function () {
    $connection = DB::connection();
    $disk = Storage::disk('local');
    $old = VersionedStorage::put('batch-independent', 'old');
    $current = VersionedStorage::put('batch-independent', 'current', cleanup: true);
    $stage = VersionedStorage::stageDirectory('recover-independent', static fn (FilesystemAdapter $files): bool => $files->put('one', 'discarded'));
    $connection->table('versioned_storage_stages')->where('version', $stage->version)->update([
        'state' => 'discarding', 'cleanup_after' => now()->subMinute(),
    ]);
    $failures = [];
    $report = static function (Throwable $failure) use (&$failures): void {
        $failures[] = $failure;
    };
    $cleanup = new CleanupStorage($connection, new ReconcileDeletions($connection));
    $connection->statement("CREATE TRIGGER reject_action_recovery BEFORE INSERT ON versioned_storage_versions WHEN NEW.pending_delete = 1 BEGIN SELECT RAISE(FAIL, 'recovery unavailable'); END");

    try {
        expect($cleanup->cleanup(1, $report))->toBeFalse()
            ->and($failures)->toHaveCount(1)
            ->and($failures[0])->toBeInstanceOf(QueryException::class);
        $disk->assertMissing("batch-independent/{$old}");
        $disk->assertExists(["batch-independent/{$current}", "recover-independent/{$stage->version}/one"]);
        expect($connection->table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('discarding');
    } finally {
        $connection->statement('DROP TRIGGER reject_action_recovery');
    }

    VersionedStorage::directory('recover-independent')->recoverStages();
    expect($connection->table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeFalse()
        ->and($connection->table('versioned_storage_versions')->where('version', $stage->version)->value('pending_delete'))->toBe(1);
    $disk->assertExists("recover-independent/{$stage->version}/one");
    expect($cleanup->cleanup(1, $report))->toBeTrue()
        ->and($failures)->toHaveCount(1)
        ->and(VersionedStorage::get('batch-independent'))->toBe('current');
    $disk->assertMissing("recover-independent/{$stage->version}");
});

it('rechecks retirement and current references when reconciling individually supplied worker receipts', function () {
    $connection = DB::connection();
    $old = VersionedStorage::put('final-guard', 'old');
    $current = VersionedStorage::put('final-guard', 'current', cleanup: true);
    $connection->table('versioned_storage_versions')->where('version', $current)->update(['pending_delete' => true]);
    $ids = $connection->table('versioned_storage_versions')->pluck('id', 'version')->all();
    $failures = [];
    $success = (new ReconcileDeletions($connection))->reconcile([
        ['version' => $old, 'error' => 'filesystem unavailable'],
        ['version' => $current, 'error' => null],
    ], $ids, static function (Throwable $failure) use (&$failures): void {
        $failures[] = $failure;
    });

    expect($success)->toBeFalse()
        ->and($failures)->toHaveCount(1)
        ->and($failures[0]->getMessage())->toContain('filesystem unavailable')
        ->and($connection->table('versioned_storage_versions')->orderBy('id')->pluck('version')->all())->toBe([$old, $current])
        ->and($connection->table('versioned_storage_resources')->sole()->current_version)->toBe($current);
    Storage::disk('local')->assertExists(["final-guard/{$old}", "final-guard/{$current}"]);
});
