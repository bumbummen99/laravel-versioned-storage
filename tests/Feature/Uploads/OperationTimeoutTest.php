<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SkyRaptor\LaravelVersionedStorage\Testing\VersionedStorageTesting;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

beforeEach(function () {
    VersionedStorageTesting::restoreUploadExecution();
    config()->set('laravel-versioned-storage.upload_timeout', 1);
    Storage::extend('operation-timeout', static function ($app, array $config): FilesystemAdapter {
        $adapter = new class($config['root'], $config['stall']) extends LocalFilesystemAdapter
        {
            public function __construct(string $root, private readonly string $stall)
            {
                parent::__construct($root);
            }

            private function pause(string $operation): void
            {
                if ($this->stall === $operation) {
                    sleep(3);
                }
            }

            public function createDirectory(string $path, Config $config): void
            {
                $this->pause(__FUNCTION__);
                parent::createDirectory($path, $config);
            }

            public function write(string $path, string $contents, Config $config): void
            {
                $this->pause(__FUNCTION__);

                if ($this->stall === 'sequential') {
                    usleep(650000);
                }
                parent::write($path, $contents.'|'.getmypid(), $config);
            }

            public function read(string $path): string
            {
                $this->pause(__FUNCTION__);

                return parent::read($path);
            }

            public function copy(string $source, string $destination, Config $config): void
            {
                $this->pause(__FUNCTION__);
                parent::copy($source, $destination, $config);
            }

            public function move(string $source, string $destination, Config $config): void
            {
                $this->pause(__FUNCTION__);
                parent::move($source, $destination, $config);
            }

            public function setVisibility(string $path, string $visibility): void
            {
                $this->pause(__FUNCTION__);
                parent::setVisibility($path, $visibility);
            }

            public function delete(string $path): void
            {
                $this->pause(__FUNCTION__);
                parent::delete($path);
            }

            public function deleteDirectory(string $path): void
            {
                $this->pause(__FUNCTION__);
                parent::deleteDirectory($path);
            }
        };

        return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
    });
});

it('gives sequential filesystem writes independent real child timeouts', function () {
    $parent = getmypid();
    Storage::set('operations', Storage::build([
        'driver' => 'operation-timeout', 'root' => Storage::disk('local')->path(''), 'stall' => 'sequential',
    ]));
    $started = hrtime(true);
    $version = VersionedStorage::directory('reports', function (FilesystemAdapter $files) use ($parent): void {
        expect(getmypid())->toBe($parent);
        $files->put('one', 'one');
        $files->put('two', 'two');
    }, disk: 'operations');

    expect((hrtime(true) - $started) / 1_000_000_000)->toBeGreaterThan(1.2)
        ->and(DB::table('versioned_storage_operations')->count())->toBe(0);
    foreach (['one', 'two'] as $file) {
        [$contents, $writer] = explode('|', Storage::disk('local')->get("reports/{$version}/{$file}"));
        expect($contents)->toBe($file)->and((int) $writer)->not->toBe($parent);
    }
});

it('times out individual filesystem calls and never publishes their stage', function (string $operation) {
    Storage::set('operations', Storage::build([
        'driver' => 'operation-timeout', 'root' => Storage::disk('local')->path(''), 'stall' => $operation,
    ]));
    $started = hrtime(true);
    expect(fn () => VersionedStorage::directory('reports', function (FilesystemAdapter $files) use ($operation): void {
        $files->put('source', 'contents');
        match ($operation) {
            'copy' => $files->copy('source', 'target'),
            'move' => $files->move('source', 'target'),
            'setVisibility' => $files->setVisibility('source', 'private'),
            'delete' => $files->delete('source'),
            'deleteDirectory' => $files->deleteDirectory(''),
            'read' => $files->get('source'),
            default => null,
        };
    }, disk: 'operations'))->toThrow(RuntimeException::class, 'timed out');

    expect((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(2.8)
        ->and(VersionedStorage::directory('reports', disk: 'operations')->versions())->toBe([])
        ->and(DB::table('versioned_storage_operations')->count())->toBe($operation === 'read' ? 0 : 1);
})->with(['createDirectory', 'write', 'copy', 'move', 'setVisibility', 'delete', 'deleteDirectory', 'read']);
