<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use PhpBench\Attributes as Bench;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

#[Bench\BeforeMethods(['setUp'])]
#[Bench\AfterMethods(['tearDown'])]
#[Bench\Iterations(10)]
#[Bench\Revs(10)]
#[Bench\Warmup(2)]
final class StorageBench
{
    private StorageEnvironment $environment;

    private int $resource = 0;

    public function setUp(): void
    {
        $this->environment = new StorageEnvironment;
        $this->environment->boot();
        $this->resource = 0;

        VersionedStorage::put('file.txt', str_repeat('x', 1024));
        VersionedStorage::directory('reports', static function (FilesystemAdapter $files): void {
            $files->put('summary.txt', str_repeat('x', 1024));
        });
    }

    public function tearDown(): void
    {
        $this->environment->shutdown();
    }

    #[Bench\Revs(1000)]
    #[Bench\Warmup(20)]
    public function benchReadFile(): void
    {
        VersionedStorage::get('file.txt');
    }

    #[Bench\Revs(1000)]
    #[Bench\Warmup(20)]
    public function benchFileExists(): void
    {
        VersionedStorage::exists('file.txt');
    }

    #[Bench\Revs(1000)]
    #[Bench\Warmup(20)]
    public function benchListVersions(): void
    {
        VersionedStorage::versions('file.txt');
    }

    public function benchWriteExistingFile(): void
    {
        VersionedStorage::put('file.txt', str_repeat('x', 1024));
    }

    public function benchRegisterFile(): void
    {
        VersionedStorage::put('new-file-'.++$this->resource, str_repeat('x', 1024));
    }

    #[Bench\Revs(1000)]
    #[Bench\Warmup(20)]
    public function benchReadDirectoryChild(): void
    {
        VersionedStorage::directory('reports')->get('summary.txt');
    }

    public function benchWriteDirectory(): void
    {
        VersionedStorage::directory('reports', static function (FilesystemAdapter $files): void {
            $files->put('summary.txt', str_repeat('x', 1024));
        });
    }

    #[Bench\Revs(100)]
    #[Bench\Warmup(10)]
    public function benchReplaceAndCleanupFile(): void
    {
        VersionedStorage::put('file.txt', str_repeat('x', 1024), cleanup: true);
        VersionedStorage::cleanup('file.txt');
    }

    #[Bench\Revs(100)]
    #[Bench\Warmup(10)]
    public function benchReplaceAndCleanupDirectory(): void
    {
        VersionedStorage::directory('reports', static function (FilesystemAdapter $files): void {
            $files->put('summary.txt', str_repeat('x', 1024));
        }, cleanup: true);
        VersionedStorage::directory('reports')->cleanup();
    }
}
