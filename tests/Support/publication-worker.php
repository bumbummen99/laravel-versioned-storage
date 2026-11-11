<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Orchestra\Testbench\Foundation\Application;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Contracts\UploadExecutionPolicy;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Policies\SynchronousUploadExecution;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\ProcessWait;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageServiceProvider;

require __DIR__.'/autoload.php';

[$script, $database, $root, $barriers, $directory, $contents] = $argv;

Application::create(resolvingCallback: static function ($app) use ($database, $root): void {
    $app->booting(static function ($app) use ($database, $root): void {
        $app['config']->set('database.default', 'publication');
        $app['config']->set('database.connections.publication', [
            'driver' => 'sqlite',
            'database' => $database,
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
        ]);
        $app['config']->set('filesystems.disks.local', ['driver' => 'local', 'root' => $root]);
    });
}, options: ['extra' => ['providers' => [VersionedStorageServiceProvider::class], 'dont-discover' => ['*']]]);
app()->instance(UploadExecutionPolicy::class, app(SynchronousUploadExecution::class));

$handler = new VersionedStorageHandler('concurrent', 'local', $directory === 'directory');
$stage = $handler->stage(static function (FilesystemAdapter $files, string $version) use ($barriers, $directory, $contents): void {
    $path = $directory === 'directory' ? 'one' : $version;
    $files->put($path, $contents.':partial');
    fwrite(STDOUT, "uploading\n");
    fflush(STDOUT);
    ProcessWait::until(static fn (): bool => file_exists($barriers.'/finish-upload'), [], 10);
    $files->put($path, $contents.':completed');
});
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
ProcessWait::until(static fn (): bool => file_exists($barriers.'/publish'), [], 10);
fwrite(STDOUT, "publishing\n");
fflush(STDOUT);
$stage->publish(cleanup: true);
fwrite(STDOUT, "published\n");
