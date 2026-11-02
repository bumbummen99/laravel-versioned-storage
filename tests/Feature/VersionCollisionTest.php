<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use Symfony\Component\Process\Process;

it('does not overwrite a published file when the version generator repeats a UUID', function () {
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);

    try {
        $version = VersionedStorage::put('collision', 'original');

        expect(fn () => VersionedStorage::put('collision', 'replacement', true))
            ->toThrow(RuntimeException::class);

        expect(VersionedStorage::versions('collision'))->toBe([$version])
            ->and(VersionedStorage::get('collision', $version))->toBe('original')
            ->and(DB::table('versioned_storage_versions')->count())->toBe(1);
        Storage::disk('local')->assertExists("collision/{$version}");
    } finally {
        Str::createUuidsNormally();
    }
});

it('does not merge a failed publication into an existing directory snapshot on a UUID collision', function () {
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);

    try {
        $version = VersionedStorage::directory('collision', function (FilesystemAdapter $files): void {
            $files->put('original.txt', 'original');
        });

        expect(fn () => VersionedStorage::directory('collision', function (FilesystemAdapter $files): void {
            $files->put('replacement.txt', 'replacement');
        }, true))->toThrow(RuntimeException::class);

        expect(VersionedStorage::directory('collision')->versions())->toBe([$version])
            ->and(VersionedStorage::directory('collision')->get('original.txt', $version))->toBe('original')
            ->and(Storage::disk('local')->allFiles("collision/{$version}"))
            ->toBe(["collision/{$version}/original.txt"]);
    } finally {
        Str::createUuidsNormally();
    }
});

it('does not delete an existing snapshot when a colliding directory callback throws', function () {
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);

    try {
        $version = VersionedStorage::directory('collision', function (FilesystemAdapter $files): void {
            $files->put('original.txt', 'original');
        });

        expect(fn () => VersionedStorage::directory('collision', function (): void {
            throw new RuntimeException('callback failed');
        }))->toThrow(RuntimeException::class);

        expect(VersionedStorage::directory('collision')->versions())->toBe([$version])
            ->and(VersionedStorage::directory('collision')->exists('original.txt', $version))->toBeTrue()
            ->and(VersionedStorage::directory('collision')->get('original.txt', $version))->toBe('original');
    } finally {
        Str::createUuidsNormally();
    }
});

it('rejects a collision with an unpublished directory snapshot before invoking its callback', function () {
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);
    $called = false;
    $callback = function () use (&$called): void {
        $called = true;
    };

    try {
        $version = VersionedStorage::directory('collision', function (FilesystemAdapter $files) use ($callback): void {
            $files->put('original.txt', 'original');

            expect(fn () => VersionedStorage::directory('collision', $callback))
                ->toThrow(RuntimeException::class);
            expect($files->allFiles())->toBe(['original.txt']);
        });

        expect($called)->toBeFalse()
            ->and(VersionedStorage::directory('collision')->get('original.txt', $version))->toBe('original');
    } finally {
        Str::createUuidsNormally();
    }
});

it('does not reuse a retired version UUID after cleanup', function () {
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);

    try {
        $first = VersionedStorage::put('collision', 'original');
        Str::createUuidsNormally();
        $current = VersionedStorage::put('collision', 'current', true);
        Storage::disk('local')->assertMissing("collision/{$first}");
        Str::createUuidsUsing(fn () => $uuid);

        expect(fn () => VersionedStorage::put('collision', 'reused'))->toThrow(RuntimeException::class);
        expect(VersionedStorage::versions('collision'))->toBe([$current])
            ->and(VersionedStorage::exists('collision', $first))->toBeFalse()
            ->and(VersionedStorage::get('collision'))->toBe('current');
    } finally {
        Str::createUuidsNormally();
    }
});

it('preserves an outer transaction after a duplicate version reservation fails', function () {
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);
    $connection = DB::connection();
    $connection->beginTransaction();

    try {
        $version = VersionedStorage::put('collision', 'original');
        expect(fn () => VersionedStorage::put('collision', 'replacement'))->toThrow(RuntimeException::class);
        expect($connection->transactionLevel())->toBe(1)
            ->and(VersionedStorage::get('collision'))->toBe('original');
        $connection->commit();

        expect(VersionedStorage::get('collision', $version))->toBe('original');
    } finally {
        Str::createUuidsNormally();

        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }
    }
});

it('preserves unregistered data at a generated version path', function (bool $directory) {
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);
    $path = $directory ? "collision/{$uuid}/original.txt" : "collision/{$uuid}";
    Storage::disk('local')->put($path, 'unregistered');

    try {
        expect(fn () => $directory
            ? VersionedStorage::directory('collision', function (FilesystemAdapter $files): void {
                $files->put('original.txt', 'replacement');
            })
            : VersionedStorage::put('collision', 'replacement'))
            ->toThrow(RuntimeException::class, 'already exists');

        expect(Storage::disk('local')->get($path))->toBe('unregistered')
            ->and(DB::table('versioned_storage_versions')->count())->toBe(0);
    } finally {
        Str::createUuidsNormally();
    }
})->with([false, true]);

it('reserves existing versions when upgrading the reservations migration', function () {
    $version = VersionedStorage::put('existing', 'original');
    $migration = require __DIR__.'/../../database/migrations/2026_01_01_000003_create_versioned_storage_reservations_table.php';
    $migration->down();
    $migration->up();
    Str::createUuidsUsing(fn () => $version);

    try {
        expect(DB::table('versioned_storage_reservations')->pluck('version')->all())->toBe([$version]);
        expect(fn () => VersionedStorage::put('existing', 'replacement'))->toThrow(RuntimeException::class);
        expect(VersionedStorage::get('existing'))->toBe('original');
    } finally {
        Str::createUuidsNormally();
    }
});

it('rejects a colliding writer in another process while the first snapshot is still unpublished', function () {
    $database = tempnam(sys_get_temp_dir(), 'versioned-storage-collision-');
    config()->set('database.connections.collision', [
        'driver' => 'sqlite',
        'database' => $database,
        'foreign_key_constraints' => true,
        'busy_timeout' => 5000,
    ]);
    config()->set('laravel-versioned-storage.connection', 'collision');
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);
    $process = null;

    try {
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $path) {
            (require $path)->up();
        }

        $version = VersionedStorage::directory('protected', function (FilesystemAdapter $files) use (&$process, $database, $uuid): void {
            $files->put('one', 'original');
            $process = new Process([
                PHP_BINARY, __DIR__.'/../Support/cleanup-worker.php',
                $database, Storage::disk('local')->path(''), 'collision', (string) $uuid,
            ]);
            $process->setTimeout(10);

            expect($process->run())->toBe(0, $process->getErrorOutput().$process->getOutput())
                ->and($process->getOutput())->toContain('rejected:')->not->toContain("accepted\n")
                ->and($files->get('one'))->toBe('original')
                ->and(VersionedStorage::directory('protected')->versions())->toBe([]);
        });

        expect(VersionedStorage::directory('protected')->get('one', $version))->toBe('original')
            ->and(VersionedStorage::directory('protected')->versions())->toBe([$version]);
    } finally {
        $process?->stop();
        Str::createUuidsNormally();
        config()->set('laravel-versioned-storage.connection', null);
        DB::purge('collision');
        unlink($database);
    }
});
