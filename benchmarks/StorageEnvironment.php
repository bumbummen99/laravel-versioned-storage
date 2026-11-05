<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\Foundation\Application;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageServiceProvider;

final class StorageEnvironment extends Application
{
    private string $database;

    public function boot(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'versioned-storage-benchmark-');

        if ($database === false) {
            throw new RuntimeException('Unable to create the benchmark metadata database.');
        }
        $this->database = $database;
        $this->app = $this->createApplication();
        $kernel = $this->app->make(Kernel::class);
        $status = $kernel->call('migrate', [
            '--path' => VERSIONED_STORAGE_BENCHMARK_ROOT.'/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($status !== 0) {
            throw new RuntimeException($kernel->output());
        }

        $disk = Storage::fake('benchmark-'.bin2hex(random_bytes(8)));
        config()->set('filesystems.disks.benchmark', ['driver' => 'local', 'root' => $disk->path('')]);
        config()->set('filesystems.default', 'benchmark');
    }

    public function shutdown(): void
    {
        $this->app->terminate();
        Storage::disk('benchmark')->deleteDirectory('');
        DB::disconnect();
        unlink($this->database);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => $this->database,
            'foreign_key_constraints' => true,
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [VersionedStorageServiceProvider::class];
    }
}
