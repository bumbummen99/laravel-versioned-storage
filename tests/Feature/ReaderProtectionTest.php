<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use Symfony\Component\Process\Process;

it('holds reader protection until the filesystem operation completes', function (bool $directory, bool $explicit, bool $exists) {
    $database = tempnam(sys_get_temp_dir(), 'versioned-storage-lock-');
    config()->set('database.connections.concurrency', [
        'driver' => 'sqlite',
        'database' => $database,
        'foreign_key_constraints' => true,
        'busy_timeout' => 5000,
    ]);
    config()->set('laravel-versioned-storage.connection', 'concurrency');
    $migrations = array_map(fn (string $path) => require $path, glob(__DIR__.'/../../database/migrations/*.php'));

    foreach ($migrations as $migration) {
        $migration->up();
    }

    $process = null;

    try {
        $version = $directory
            ? VersionedStorage::directory('protected', function (FilesystemAdapter $files): void {
                $files->put('one', 'original');
            })
            : VersionedStorage::put('protected', 'original');

        $disk = Storage::disk('local');
        $baseAdapter = $disk->getAdapter();
        $adapter = Mockery::mock($baseAdapter)->makePartial();
        $adapter->shouldReceive($exists ? 'fileExists' : 'read')->once()
            ->andReturnUsing(function (string $path) use (&$process, $database, $disk, $directory, $exists, $baseAdapter): mixed {
                $process = new Process([
                    PHP_BINARY, __DIR__.'/../Support/cleanup-worker.php',
                    $database, $disk->path(''), $directory ? 'directory' : 'file',
                ]);
                $process->setTimeout(10);
                $process->start();

                if (! $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'transaction-started'))) {
                    throw new RuntimeException('Worker failed: '.$process->getErrorOutput().$process->getOutput());
                }
                usleep(100000);

                if (! $process->isRunning()) {
                    throw new RuntimeException('Worker exited: '.$process->getErrorOutput().$process->getOutput());
                }
                expect($process->isRunning())->toBeTrue($process->getErrorOutput().$process->getOutput())
                    ->and($process->getOutput())->not->toContain('finished');

                return $exists ? $baseAdapter->fileExists($path) : $baseAdapter->read($path);
            });
        Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, $disk->getConfig()));

        $selected = $explicit ? $version : null;
        $result = $directory
            ? ($exists ? VersionedStorage::directory('protected')->exists('one', $selected) : VersionedStorage::directory('protected')->get('one', $selected))
            : ($exists ? VersionedStorage::exists('protected', $selected) : VersionedStorage::get('protected', $selected));

        expect($result)->toBe($exists ? true : 'original');
        expect($process->wait())->toBe(0, $process->getErrorOutput().$process->getOutput());
        Storage::set('local', $disk);
        expect($directory ? VersionedStorage::directory('protected')->get('one') : VersionedStorage::get('protected'))
            ->toBe('replacement');
        $disk->assertMissing($directory ? "protected/{$version}/one" : "protected/{$version}");
    } finally {
        $process?->stop();
        config()->set('laravel-versioned-storage.connection', null);
        DB::purge('concurrency');
        unlink($database);
    }
})->with([
    'current file read' => [false, false, false],
    'explicit file read' => [false, true, false],
    'current directory read' => [true, false, false],
    'explicit directory read' => [true, true, false],
    'file existence check' => [false, true, true],
    'directory child existence check' => [true, true, true],
]);
