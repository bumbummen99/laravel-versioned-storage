<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('rejects overlapping resources registered through a second disk name for the same filesystem', function () {
    $local = Storage::disk('local');
    Storage::set('local-alias', $local);

    try {
        $parent = VersionedStorage::directory('shared-reports', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'parent contents');
        }, disk: 'local');
        $childPath = "shared-reports/{$parent}/independent";
        expect(fn () => VersionedStorage::put($childPath, 'independent contents', disk: 'local-alias'))
            ->toThrow(InvalidArgumentException::class, 'overlaps registered resource');

        $replacement = VersionedStorage::directory('shared-reports', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'replacement contents');
        }, cleanup: true, disk: 'local');
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

        expect(VersionedStorage::directory('shared-reports', disk: 'local')->versions())->toBe([$replacement])
            ->and(VersionedStorage::directory('shared-reports', disk: 'local')->get('summary.txt'))->toBe('replacement contents')
            ->and(VersionedStorage::versions($childPath, 'local-alias'))->toBe([])
            ->and(VersionedStorage::exists($childPath, disk: 'local-alias'))->toBeFalse();
        $local->assertMissing("shared-reports/{$parent}/summary.txt");
        $local->assertMissing($childPath);
    } finally {
        Storage::forgetDisk('local-alias');
    }
});

it('protects legacy overlapping resources registered through a second disk name during cleanup', function () {
    $local = Storage::disk('local');
    Storage::set('local-alias', $local);

    try {
        $parent = VersionedStorage::directory('shared-legacy', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'parent contents');
        }, disk: 'local');
        $childPath = "shared-legacy/{$parent}/independent";
        $child = (string) Str::uuid();
        $resourceId = DB::table('versioned_storage_resources')->insertGetId([
            'key' => hash('sha256', "local-alias\0{$childPath}\0file"),
            'disk' => 'local-alias',
            'path' => $childPath,
            'is_directory' => false,
        ]);
        DB::table('versioned_storage_versions')->insert([
            'resource_id' => $resourceId,
            'version' => $child,
            'created_at' => now(),
        ]);
        $local->put("{$childPath}/{$child}", 'independent contents');

        VersionedStorage::directory('shared-legacy', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'replacement contents');
        }, cleanup: true, disk: 'local');
        expect(fn () => VersionedStorage::directory('shared-legacy', disk: 'local')->cleanup())
            ->toThrow(function (RuntimeException $exception) use ($parent, $childPath): void {
                expect($exception->getMessage())->toBe("Cannot remove version [{$parent}]: it contains registered resource [{$childPath}].")
                    ->and($exception->getCode())->toBe(0)
                    ->and($exception->getPrevious())->toBeNull();
            });

        expect(VersionedStorage::directory('shared-legacy', disk: 'local')->get('summary.txt'))
            ->toBe('replacement contents')
            ->and(VersionedStorage::versions($childPath, 'local-alias'))->toBe([$child])
            ->and(VersionedStorage::get($childPath, $child, 'local-alias'))->toBe('independent contents')
            ->and(DB::table('versioned_storage_versions')->where('version', $parent)->value('pending_delete'))->toBe(1);
        $local->assertExists("shared-legacy/{$parent}/summary.txt");
        $local->assertExists("{$childPath}/{$child}");
    } finally {
        Storage::forgetDisk('local-alias');
    }
});
