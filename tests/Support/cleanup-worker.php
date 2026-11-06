<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Orchestra\Testbench\Foundation\Application;
use SkyRaptor\LaravelVersionedStorage\Testing\SynchronousUploadExecution;
use SkyRaptor\LaravelVersionedStorage\Uploads\UploadExecutionPolicy;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageServiceProvider;

require __DIR__.'/../../vendor/autoload.php';

[$script, $database, $root, $directory] = $argv;

Application::create(resolvingCallback: function ($app) use ($database, $root): void {
    $app->booting(function ($app) use ($database, $root): void {
        $app['config']->set('database.default', 'concurrency');
        $app['config']->set('database.connections.concurrency', [
            'driver' => 'sqlite',
            'database' => $database,
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
        ]);
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('filesystems.disks.local', ['driver' => 'local', 'root' => $root]);
    });
}, options: ['extra' => ['providers' => [VersionedStorageServiceProvider::class], 'dont-discover' => ['*']]]);
app()->instance(UploadExecutionPolicy::class, app(SynchronousUploadExecution::class));

if (in_array($directory, ['directory', 'file'], true)) {
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $event): void {
        if ($event->connection->transactionLevel() !== 1) {
            return;
        }
        fwrite(STDOUT, "transaction-started\n");
        fflush(STDOUT);
    });
}

if ($directory === 'register') {
    fwrite(STDOUT, "registering\n");
    fflush(STDOUT);

    try {
        VersionedStorage::put($argv[4], 'child contents');
        fwrite(STDOUT, "accepted\n");
    } catch (InvalidArgumentException $exception) {
        fwrite(STDOUT, 'rejected: '.$exception->getMessage()."\n");
    }
} elseif ($directory === 'collision') {
    Str::createUuidsUsing(fn () => $argv[4]);

    try {
        VersionedStorage::directory('protected', function (FilesystemAdapter $files): void {
            $files->put('one', 'replacement');
        });
        fwrite(STDOUT, "accepted\n");
    } catch (QueryException $exception) {
        fwrite(STDOUT, 'rejected: '.$exception->getMessage()."\n");
    }
} elseif ($directory === 'directory') {
    VersionedStorage::directory('protected', function (FilesystemAdapter $files): void {
        $files->put('one', 'replacement');
    }, true);
} else {
    VersionedStorage::put('protected', 'replacement', true);
}

if (in_array($directory, ['directory', 'file'], true)) {
    config()->set('concurrency.default', 'sync');

    if (Artisan::call('laravel-versioned-storage:cleanup') !== 0) {
        throw new RuntimeException(Artisan::output());
    }
}
fwrite(STDOUT, "finished\n");
