<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('uses the configured metadata connection for migrations and operations', function () {
    config()->set('database.connections.metadata', config('database.connections.testing'));
    config()->set('laravel-versioned-storage.connection', 'metadata');
    $migration = require TestPaths::root('database/migrations/2026_01_01_000000_create_versioned_storage_tables.php');
    $cleanupMigration = require TestPaths::root('database/migrations/2026_01_01_000001_add_pending_delete_to_versioned_storage_versions.php');
    $disksMigration = require TestPaths::root('database/migrations/2026_01_01_000002_create_versioned_storage_disks_table.php');
    $reservationsMigration = require TestPaths::root('database/migrations/2026_01_01_000003_create_versioned_storage_reservations_table.php');
    $stagesMigration = require TestPaths::root('database/migrations/2026_01_01_000004_create_versioned_storage_stages_table.php');
    $deadlineMigration = require TestPaths::root('database/migrations/2026_01_01_000005_add_cleanup_after_to_versioned_storage_stages.php');
    $operationsMigration = require TestPaths::root('database/migrations/2026_01_01_000006_create_versioned_storage_operations_table.php');
    $migration->up();
    $cleanupMigration->up();
    $disksMigration->up();
    $reservationsMigration->up();
    $stagesMigration->up();
    $deadlineMigration->up();
    $operationsMigration->up();

    try {
        $version = VersionedStorage::put('connection', 'contents');
        expect(VersionedStorage::get('connection'))->toBe('contents')
            ->and(DB::connection('metadata')->table('versioned_storage_versions')->value('version'))->toBe($version)
            ->and(DB::connection('testing')->table('versioned_storage_versions')->count())->toBe(0);
    } finally {
        $operationsMigration->down();
        $deadlineMigration->down();
        $stagesMigration->down();
        $reservationsMigration->down();
        $disksMigration->down();
        $cleanupMigration->down();
        $migration->down();
        DB::purge('metadata');
        config()->set('laravel-versioned-storage.connection', null);
    }
});
