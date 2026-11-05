<?php

namespace Workbench\App\Providers;

use Carbon\Carbon;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        if (getenv('VERSIONED_STORAGE_TEST_DB') === false) {
            return;
        }

        $root = getenv('VERSIONED_STORAGE_TEST_ROOT');

        if ($root === false || $root === '') {
            throw new RuntimeException('Upload execution tests require an isolated storage root.');
        }

        $this->app['config']->set([
            'database.default' => 'uploads',
            'database.connections.uploads' => [
                'driver' => 'sqlite', 'database' => getenv('VERSIONED_STORAGE_TEST_DB'), 'foreign_key_constraints' => true,
            ],
            'filesystems.default' => 'local',
            'filesystems.disks.local' => ['driver' => 'local', 'root' => $root],
            'queue.default' => 'database',
            'queue.failed.driver' => null,
            'queue.connections.database' => [
                'driver' => 'database', 'connection' => 'uploads', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 60,
            ],
            'cache.default' => 'array',
            'session.driver' => 'array',
            'laravel-versioned-storage.upload_timeout' => (int) (getenv('VERSIONED_STORAGE_TEST_TIMEOUT') ?: 1),
            'laravel-versioned-storage.cleanup_grace' => 10800,
            'concurrency.default' => 'sync',
        ]);

        if (getenv('VERSIONED_STORAGE_TEST_NOW') !== false) {
            Carbon::setTestNow(getenv('VERSIONED_STORAGE_TEST_NOW'));
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (getenv('VERSIONED_STORAGE_TEST_DB') !== false && $this->app->runningInConsole()) {
            Event::listen(JobExceptionOccurred::class, static function (JobExceptionOccurred $event): void {
                fwrite(STDERR, (string) $event->exception);
            });
        }
    }
}
