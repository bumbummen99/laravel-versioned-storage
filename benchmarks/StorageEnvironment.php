<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\Foundation\Application;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageServiceProvider;

final class StorageEnvironment extends Application
{
    public function boot(): void
    {
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

        Storage::set('benchmark', Storage::fake('benchmark-'.bin2hex(random_bytes(8))));
        config()->set('filesystems.default', 'benchmark');
    }

    public function shutdown(): void
    {
        $this->app->terminate();
        Storage::disk('benchmark')->deleteDirectory('');
        DB::disconnect();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [VersionedStorageServiceProvider::class];
    }
}
