<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
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

DB::connection()->beforeExecuting(function (string $sql, array $bindings, Connection $connection): void {
    if (str_starts_with($sql, 'insert or ignore into "versioned_storage_resources"')) {
        fwrite(STDOUT, "publishing\n");
        fflush(STDOUT);
    }
});

if ($directory === 'directory') {
    VersionedStorage::directory('protected', function (FilesystemAdapter $files): void {
        $files->put('one', 'replacement');
    }, true);
} else {
    VersionedStorage::put('protected', 'replacement', true);
}

fwrite(STDOUT, "finished\n");
