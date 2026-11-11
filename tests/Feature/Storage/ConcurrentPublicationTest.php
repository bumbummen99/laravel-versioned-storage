<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\ProcessWait;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\SubprocessFixture;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;
use Symfony\Component\Process\Process;

it('keeps simultaneous uploads isolated and serializes their competing publications', function (bool $directory) {
    $fixture = new SubprocessFixture('concurrent-publication');

    try {
        $connection = DB::connection('concurrent-publication');
        $disk = Storage::disk('local');
        $handler = new VersionedStorageHandler('concurrent', 'local', $directory);
        $original = $handler->write(static function (FilesystemAdapter $files, string $version) use ($directory): void {
            $files->put($directory ? 'one' : $version, 'original');
        });
        $workers = [];

        foreach (['first', 'second'] as $contents) {
            $workers[] = $fixture->start([
                PHP_BINARY, TestPaths::root('tests/Support/publication-worker.php'),
                $fixture->database, $disk->path(''), $fixture->directory,
                $directory ? 'directory' : 'file', $contents,
            ], 20);
        }

        ProcessWait::until(static fn (): bool => collect($workers)->every(
            static fn (Process $worker): bool => str_contains($worker->getOutput(), "uploading\n"),
        ), $workers, 10);
        $stages = $connection->table('versioned_storage_stages')->where('state', 'uploading')->pluck('version')->all();
        expect($stages)->toHaveCount(2)
            ->and($handler->versions())->toBe([$original]);
        $handler->cleanup();
        expect($connection->table('versioned_storage_stages')->where('state', 'uploading')->count())->toBe(2);
        expect(touch($fixture->directory.'/finish-upload'))->toBeTrue();

        ProcessWait::until(static fn (): bool => collect($workers)->every(
            static fn (Process $worker): bool => str_contains($worker->getOutput(), "ready\n"),
        ), $workers, 10);
        $uploaded = array_map(static fn (string $version): string => $disk->get(
            "concurrent/{$version}".($directory ? '/one' : ''),
        ), $stages);
        sort($uploaded);
        expect($uploaded)->toBe(['first:completed', 'second:completed'])
            ->and($connection->table('versioned_storage_operations')->count())->toBe(0);

        MetadataTransaction::run($connection, function () use ($connection, $original, $fixture, $workers): void {
            $connection->table('versioned_storage_resources')->where('path', 'concurrent')
                ->update(['current_version' => $original]);
            expect(touch($fixture->directory.'/publish'))->toBeTrue();
            ProcessWait::until(static fn (): bool => collect($workers)->every(
                static fn (Process $worker): bool => str_contains($worker->getOutput(), "publishing\n"),
            ), $workers, 10);
            ProcessWait::observe(static fn (): bool => collect($workers)->every(
                static fn (Process $worker): bool => ! str_contains($worker->getOutput(), "published\n"),
            ), $workers, 0.1);
        });

        foreach ($workers as $worker) {
            expect($worker->wait())->toBe(0, $worker->getOutput().$worker->getErrorOutput());
        }

        $published = $connection->table('versioned_storage_versions')->orderBy('id')->pluck('version')->all();
        $current = $published[2];
        expect($published)->toHaveCount(3)
            ->and(array_slice($published, 1))->toEqualCanonicalizing($stages)
            ->and($handler->versions())->toBe([$current])
            ->and($handler->get('one'))->toBeIn(['first:completed', 'second:completed'])
            ->and($connection->table('versioned_storage_stages')->where('state', 'published')->count())->toBe(3)
            ->and($connection->table('versioned_storage_resources')->sole()->current_version)->toBe($current);

        $handler->cleanup();
        $handler->cleanup();
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        expect($connection->table('versioned_storage_versions')->pluck('version')->all())->toBe([$current])
            ->and($disk->allFiles('concurrent'))->toBe(["concurrent/{$current}".($directory ? '/one' : '')]);
    } finally {
        $fixture->close();
    }
})->with(['file' => false, 'directory' => true]);
