<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;

afterEach(function (): void {
    Mockery::close();

    if (isset($this->root)) {
        (new Filesystem)->deleteDirectory($this->root);
    }
});

it('removes a physical file snapshot without touching another version', function () {
    $this->root = dirname(__DIR__, 2).'/.deletion-'.bin2hex(random_bytes(8));
    $adapter = new LocalFilesystemAdapter($this->root);
    $disk = new FilesystemAdapter(new Flysystem($adapter), $adapter);
    $disk->put('report/old', 'retired');
    $disk->put('report/current', 'current');

    SnapshotDeletion::remove($disk, 'report/old', false);

    expect($disk->exists('report/old'))->toBeFalse()
        ->and($disk->get('report/current'))->toBe('current');
});

it('removes a physical directory snapshot recursively and preserves its sibling', function () {
    $this->root = dirname(__DIR__, 2).'/.deletion-'.bin2hex(random_bytes(8));
    $adapter = new LocalFilesystemAdapter($this->root);
    $disk = new FilesystemAdapter(new Flysystem($adapter), $adapter);
    $disk->put('reports/old/nested/one', 'retired');
    $disk->put('reports/old/two', 'retired too');
    $disk->put('reports/current/one', 'current');

    SnapshotDeletion::remove($disk, 'reports/old', true);

    expect($disk->exists('reports/old'))->toBeFalse()
        ->and($disk->get('reports/current/one'))->toBe('current');
});

it('accepts removal of an already absent physical snapshot', function (bool $directory) {
    $this->root = dirname(__DIR__, 2).'/.deletion-'.bin2hex(random_bytes(8));
    $adapter = new LocalFilesystemAdapter($this->root);
    $disk = new FilesystemAdapter(new Flysystem($adapter), $adapter);

    SnapshotDeletion::remove($disk, 'reports/missing', $directory);

    expect($disk->exists('reports/missing'))->toBeFalse();
})->with(['file' => false, 'directory' => true]);

it('rejects refused directory deletion without checking existence', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('deleteDirectory')->once()->with('reports/old')->andReturn(false);
    $disk->shouldNotReceive('exists');

    expect(fn () => SnapshotDeletion::remove($disk, 'reports/old', true))
        ->toThrow(RuntimeException::class, 'Unable to remove version snapshot [reports/old].');
});

it('rejects directory deletion that leaves any snapshot behind', function () {
    $previous = new RuntimeException('upload failed');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('deleteDirectory')->once()->with('reports/old')->andReturn(true);
    $disk->shouldReceive('exists')->once()->with('reports/old')->andReturn(true);

    expect(fn () => SnapshotDeletion::remove($disk, 'reports/old', true, $previous))
        ->toThrow(fn (RuntimeException $exception) => expect($exception->getPrevious())->toBe($previous));
});

it('propagates driver deletion errors unchanged', function () {
    $failure = new LogicException('driver disconnected');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('delete')->once()->with('report/old')->andThrow($failure);
    $disk->shouldNotReceive('exists');

    expect(fn () => SnapshotDeletion::remove($disk, 'report/old', false))
        ->toThrow(fn (LogicException $exception) => expect($exception)->toBe($failure));
});

it('propagates verification errors instead of claiming a verified deletion', function () {
    $failure = new LogicException('verification disconnected');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('delete')->once()->with('report/old')->andReturn(true);
    $disk->shouldReceive('exists')->once()->with('report/old')->andThrow($failure);

    expect(fn () => SnapshotDeletion::remove($disk, 'report/old', false))
        ->toThrow(fn (LogicException $exception) => expect($exception)->toBe($failure));
});

it('preserves the original failure when the filesystem refuses deletion', function () {
    $previous = new RuntimeException('publication failed');
    $filesystem = Mockery::mock(FilesystemAdapter::class);
    $filesystem->shouldReceive('delete')->once()->with('report/version')->andReturn(false);
    $filesystem->shouldNotReceive('exists');

    expect(fn () => SnapshotDeletion::remove($filesystem, 'report/version', false, $previous))
        ->toThrow(function (RuntimeException $exception) use ($previous): void {
            expect($exception->getMessage())->toBe('Unable to remove version snapshot [report/version].')
                ->and($exception->getCode())->toBe(0)
                ->and($exception->getPrevious())->toBe($previous);
        });
});

it('preserves the original failure when a successful deletion leaves the file behind', function () {
    $previous = new RuntimeException('publication failed');
    $filesystem = Mockery::mock(FilesystemAdapter::class);
    $filesystem->shouldReceive('delete')->once()->with('report/version')->andReturn(true);
    $filesystem->shouldReceive('exists')->once()->with('report/version')->andReturn(true);

    expect(fn () => SnapshotDeletion::remove($filesystem, 'report/version', false, $previous))
        ->toThrow(function (RuntimeException $exception) use ($previous): void {
            expect($exception->getMessage())->toBe('Unable to remove version snapshot [report/version].')
                ->and($exception->getCode())->toBe(0)
                ->and($exception->getPrevious())->toBe($previous);
        });
});
