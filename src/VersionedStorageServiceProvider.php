<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SkyRaptor\LaravelVersionedStorage\Console\Commands\CleanupVersionsCommand;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Contracts\UploadExecutionPolicy;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Policies\UploadContext;

/**
 * Laravel integration: register config/runtime context, connect queue events, and expose console assets.
 * Application-scoped; only UploadContext tracks runtime state. Resource workflows are constructed
 * by handlers, not registered as application singletons.
 */
class VersionedStorageServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-versioned-storage.php', 'laravel-versioned-storage');

        $this->app->singleton(VersionedStorage::class);
        $this->app->singleton(UploadContext::class);
        $this->app->bind(UploadExecutionPolicy::class, UploadContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(CommandStarting::class, [UploadContext::class, 'commandStarting']);
        Event::listen(CommandFinished::class, [UploadContext::class, 'commandFinished']);
        Event::listen(JobProcessing::class, [UploadContext::class, 'jobProcessing']);
        Event::listen(JobProcessed::class, [UploadContext::class, 'jobFinished']);
        Event::listen(JobExceptionOccurred::class, [UploadContext::class, 'jobFinished']);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-versioned-storage.php' => config_path('laravel-versioned-storage.php'),
        ], ['laravel-versioned-storage', 'laravel-versioned-storage-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['laravel-versioned-storage', 'laravel-versioned-storage-migrations']);

        $this->commands([
            CleanupVersionsCommand::class,
        ]);
    }
}
