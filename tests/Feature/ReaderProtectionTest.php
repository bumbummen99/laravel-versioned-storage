<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\ProcessWait;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\SubprocessFixture;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('holds reader protection until the filesystem operation completes', function (bool $directory, bool $explicit, bool $exists) {
    $fixture = new SubprocessFixture('reader-protection');
    $database = $fixture->database;
    $disk = Storage::disk('local');
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
            ->andReturnUsing(function (string $path) use (&$process, $database, $disk, $directory, $exists, $baseAdapter, $fixture): mixed {
                $process = $fixture->start([
                    PHP_BINARY, __DIR__.'/../Support/cleanup-worker.php',
                    $database, $disk->path(''), $directory ? 'directory' : 'file',
                ]);
                ProcessWait::until(fn (): bool => str_contains($process->getOutput(), 'transaction-started'), [$process], 10);
                ProcessWait::observe(fn (): bool => ! str_contains($process->getOutput(), 'finished'), [$process], 0.1);
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
        Storage::set('local', $disk);
        $fixture->close();
    }
})->with([
    'current file read' => [false, false, false],
    'explicit file read' => [false, true, false],
    'current directory read' => [true, false, false],
    'explicit directory read' => [true, true, false],
    'file existence check' => [false, true, true],
    'directory child existence check' => [true, true, true],
]);
