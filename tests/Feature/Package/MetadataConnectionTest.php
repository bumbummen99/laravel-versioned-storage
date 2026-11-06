<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('uses the configured metadata connection for migrations and operations', function () {
    config()->set('database.connections.metadata', config('database.connections.testing'));
    config()->set('database.connections.metadata.foreign_key_constraints', true);
    config()->set('laravel-versioned-storage.connection', 'metadata');
    expect(glob(TestPaths::root('database/migrations/*.php')))->toHaveCount(1);
    $migration = require TestPaths::root('database/migrations/2026_01_01_000000_create_versioned_storage_tables.php');
    $migration->up();
    $tables = [
        'versioned_storage_resources' => ['id', 'key', 'disk', 'path', 'is_directory', 'current_version'],
        'versioned_storage_versions' => ['id', 'resource_id', 'version', 'created_at', 'pending_delete'],
        'versioned_storage_disks' => ['key'],
        'versioned_storage_reservations' => ['version'],
        'versioned_storage_stages' => ['id', 'resource_id', 'version', 'state', 'cleanup_after', 'failed'],
        'versioned_storage_operations' => ['id', 'version', 'operation', 'cleanup_after'],
    ];

    try {
        foreach ($tables as $table => $columns) {
            expect(Schema::connection('metadata')->getColumnListing($table))->toBe($columns);
        }
        foreach ([
            ['versioned_storage_resources', ['key'], 'unique'],
            ['versioned_storage_versions', ['version'], 'unique'],
            ['versioned_storage_disks', ['key'], 'primary'],
            ['versioned_storage_reservations', ['version'], 'primary'],
            ['versioned_storage_stages', ['version'], 'unique'],
            ['versioned_storage_stages', ['state'], null],
            ['versioned_storage_stages', ['state', 'cleanup_after'], null],
            ['versioned_storage_operations', ['id'], 'primary'],
            ['versioned_storage_operations', ['version'], 'unique'],
            ['versioned_storage_operations', ['cleanup_after'], null],
        ] as [$table, $columns, $type]) {
            expect(Schema::connection('metadata')->hasIndex($table, $columns, $type))->toBeTrue();
        }
        expect(Schema::connection('metadata')->getForeignKeys('versioned_storage_versions'))->toHaveCount(1)
            ->and(Schema::connection('metadata')->getForeignKeys('versioned_storage_stages'))->toHaveCount(1)
            ->and(Schema::connection('metadata')->getForeignKeys('versioned_storage_operations'))->toHaveCount(1);
        $version = VersionedStorage::put('connection', 'contents');
        $stage = VersionedStorage::stage('connection', 'staged contents');
        DB::connection('metadata')->table('versioned_storage_operations')->insert([
            'id' => (string) Str::uuid(),
            'version' => $stage->version,
            'operation' => 'write',
            'cleanup_after' => now(),
        ]);
        expect(VersionedStorage::get('connection'))->toBe('contents')
            ->and(DB::connection('metadata')->table('versioned_storage_versions')->value('version'))->toBe($version)
            ->and(DB::connection('testing')->table('versioned_storage_versions')->count())->toBe(0);
        DB::connection('metadata')->table('versioned_storage_stages')->where('version', $stage->version)->delete();
        expect(DB::connection('metadata')->table('versioned_storage_operations')->count())->toBe(0);
        $migration->down();
        foreach (array_keys($tables) as $table) {
            expect(Schema::connection('metadata')->hasTable($table))->toBeFalse()
                ->and(Schema::connection('testing')->hasTable($table))->toBeTrue();
        }
        $migration->up();
        foreach ($tables as $table => $columns) {
            expect(Schema::connection('metadata')->getColumnListing($table))->toBe($columns);
        }
    } finally {
        $migration->down();
        DB::purge('metadata');
        config()->set('laravel-versioned-storage.connection', null);
    }
});
