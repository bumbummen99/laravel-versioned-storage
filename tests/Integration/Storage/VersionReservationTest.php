<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use Symfony\Component\Process\Process;

it('does not merge a failed publication into an existing directory snapshot on a UUID collision', function () {
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);
    $called = false;

    try {
        $version = VersionedStorage::directory('collision', function (FilesystemAdapter $files): void {
            $files->put('original.txt', 'original');
        });
        expect(DB::table('versioned_storage_reservations')->pluck('version')->all())->toBe([$version]);

        expect(fn () => VersionedStorage::directory('collision', function (FilesystemAdapter $files) use (&$called): void {
            $called = true;
            $files->put('replacement.txt', 'replacement');

            throw new RuntimeException('callback failed');
        }, true))->toThrow(RuntimeException::class);

        expect($called)->toBeFalse()
            ->and(VersionedStorage::directory('collision')->versions())->toBe([$version])
            ->and(VersionedStorage::directory('collision')->get('original.txt', $version))->toBe('original')
            ->and(Storage::disk('local')->allFiles("collision/{$version}"))
            ->toBe(["collision/{$version}/original.txt"]);
    } finally {
        Str::createUuidsNormally();
    }
});

it('preserves an outer transaction when an upload is rejected after publication starts', function () {
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);
    $connection = DB::connection();
    $stage = VersionedStorage::stage('collision', 'original');

    try {
        $connection->beginTransaction();
        $version = $stage->publish();
        expect(fn () => VersionedStorage::put('collision', 'replacement'))
            ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction');
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
        foreach (glob(TestPaths::root('database/migrations/*.php')) as $path) {
            (require $path)->up();
        }

        $version = VersionedStorage::directory('protected', function (FilesystemAdapter $files) use (&$process, $database, $uuid): void {
            $files->put('one', 'original');
            $process = new Process([
                PHP_BINARY, TestPaths::root('tests/Support/cleanup-worker.php'),
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
