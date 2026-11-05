<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Orchestra\Testbench\Foundation\Application;
use SkyRaptor\LaravelVersionedStorage\Testing\SynchronousUploadExecution;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\ProcessWait;
use SkyRaptor\LaravelVersionedStorage\UploadExecutionPolicy;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageServiceProvider;

require __DIR__.'/../../vendor/autoload.php';

[$script, $database, $root, $mode] = $argv;

Application::create(resolvingCallback: static function ($app) use ($database, $root): void {
    $app->booting(static function ($app) use ($database, $root): void {
        $app['config']->set('database.default', 'recovery');
        $app['config']->set('database.connections.recovery', [
            'driver' => 'sqlite',
            'database' => $database,
            'foreign_key_constraints' => true,
            'busy_timeout' => 1000,
        ]);
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('filesystems.disks.local', ['driver' => 'local', 'root' => $root]);
        $app['config']->set('concurrency.default', 'sync');
    });
}, options: ['extra' => ['providers' => [VersionedStorageServiceProvider::class], 'dont-discover' => ['*']]]);
app()->instance(UploadExecutionPolicy::class, app(SynchronousUploadExecution::class));

if ($mode === 'cleanup') {
    $status = Artisan::call('laravel-versioned-storage:cleanup');
    fwrite(STDOUT, Artisan::output());
    exit($status);
}

$pause = static function (): never {
    fwrite(STDOUT, "writer-paused\n");
    fflush(STDOUT);

    while (true) {
        sleep(1);
    }
};

function installInterruptedUploadDisk(string $root, string $mode, Closure $pause): void
{
    $adapter = new class($root, $pause, $mode) extends LocalFilesystemAdapter
    {
        public function __construct(
            private readonly string $root,
            private readonly Closure $pause,
            private readonly string $mode,
        ) {
            parent::__construct($root);
        }

        public function writeStream(string $path, $contents, Config $config): void
        {
            parent::write($path, stream_get_contents($contents, 10), $config);

            if (in_array($this->mode, ['live-upload', 'session-loss'], true)) {
                if ($this->mode === 'session-loss') {
                    // This explicit disconnect resets Laravel's transaction state; it is not a network fault.
                    DB::connection()->disconnect();
                }
                fwrite(STDOUT, "writer-paused\n");
                fflush(STDOUT);
                ProcessWait::until(fn (): bool => file_exists($this->root.'/release-upload'), [], 10);
                // Model an accepted remote request completing after cleanup, using a local write.
                rewind($contents);
                parent::writeStream($path, $contents, $config);

                if ($this->mode === 'session-loss') {
                    fwrite(STDOUT, "storage-completed\n");
                    fflush(STDOUT);
                }

                return;
            }
            ($this->pause)();
        }
    };
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, [
        'driver' => 'local', 'root' => $root,
    ]));
}

function verifySessionLoss(string $root): never
{
    try {
        VersionedStorage::put('crash-file', new File($root.'/upload-source.txt'), cleanup: true);
    } catch (RuntimeException $exception) {
        if ($exception::class !== RuntimeException::class
            || $exception->getMessage() !== 'The upload lost its metadata connection before operation completion.') {
            throw $exception;
        }
        fwrite(STDOUT, 'upload-failed: '.$exception->getMessage()."\n");
        exit(0);
    }

    throw new LogicException('Publication unexpectedly succeeded after session loss.');
}

function pauseBeforePublication(Closure $pause): void
{
    // This fixture intentionally targets the version insert, not earlier stage commits.
    DB::connection()->beforeExecuting(static function (string $sql) use ($pause): void {
        if (str_starts_with($sql, 'insert into "versioned_storage_versions"')) {
            $pause();
        }
    });
}

function runPublicationCrash(string $root, string $mode, Closure $pause): never
{
    if ($mode === 'uncommitted-put') {
        $staged = VersionedStorage::stage('crash-file', new File($root.'/upload-source.txt'));
        DB::beginTransaction();
        $staged->publish(cleanup: true);
    } else {
        VersionedStorage::put('crash-file', new File($root.'/upload-source.txt'), cleanup: true);
    }

    if ($mode === 'live-upload') {
        exit(0);
    }

    if ($mode !== 'committed-put' && ($mode !== 'uncommitted-put' || DB::transactionLevel() !== 1)) {
        throw new LogicException('The writer missed its expected crash boundary.');
    }

    $pause();
}

function runFailedRecoveryCrash(string $root): never
{
    $adapter = new class($root) extends LocalFilesystemAdapter
    {
        public function deleteDirectory(string $path): void
        {
            throw new RuntimeException('snapshot deletion refused');
        }
    };
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, [
        'driver' => 'local', 'root' => $root,
    ]));

    try {
        VersionedStorage::directory('crash-retry', static function (FilesystemAdapter $files): void {
            $files->put('one', 'incomplete');

            throw new RuntimeException('callback failed');
        }, cleanup: true);

        throw new LogicException('The staged write unexpectedly succeeded.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'snapshot deletion refused') {
            throw $exception;
        }
    }

    DB::beginTransaction();

    if (DB::transactionLevel() !== 1
        || DB::table('versioned_storage_stages')->where('state', 'uploading')->count() !== 1) {
        throw new LogicException('The writer did not reach the uncommitted recovery state.');
    }

    fwrite(STDOUT, "recovery-pending\n");
    fflush(STDOUT);

    while (true) {
        sleep(1);
    }
}

if (in_array($mode, ['upload', 'live-upload', 'session-loss'], true)) {
    installInterruptedUploadDisk($root, $mode, $pause);
}

if ($mode === 'before-publication') {
    pauseBeforePublication($pause);
}

if ($mode === 'session-loss') {
    verifySessionLoss($root);
}

if (in_array($mode, ['upload', 'live-upload', 'before-publication', 'uncommitted-put', 'committed-put'], true)) {
    runPublicationCrash($root, $mode, $pause);
}

runFailedRecoveryCrash($root);
