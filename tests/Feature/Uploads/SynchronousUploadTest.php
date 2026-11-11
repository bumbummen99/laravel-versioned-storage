<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Contracts\UploadExecutionPolicy;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Policies\UploadContext;
use SkyRaptor\LaravelVersionedStorage\Testing\VersionedStorageTesting;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageServiceProvider;

it('runs real uploads in the test process with an in-memory database and fake disk', function () {
    VersionedStorageTesting::runUploadsSynchronously();
    Storage::fake('exports');
    $called = false;
    $pid = getmypid();

    $version = VersionedStorage::directory('reports', function (FilesystemAdapter $files) use (&$called, $pid): void {
        expect(getmypid())->toBe($pid);
        $called = true;
        $files->put('report.txt', 'contents');
    }, disk: 'exports');

    expect($called)->toBeTrue()
        ->and(VersionedStorage::directory('reports', disk: 'exports')->get('report.txt'))->toBe('contents')
        ->and(DB::table('versioned_storage_resources')->value('current_version'))->toBe($version);
    Storage::disk('exports')->assertExists("reports/{$version}/report.txt");
});

it('preserves staging publication and cleanup behavior during synchronous testing', function () {
    VersionedStorageTesting::runUploadsSynchronously();
    $first = VersionedStorage::put('report', 'first');
    $stage = VersionedStorage::stage('report', 'second');

    expect(VersionedStorage::get('report'))->toBe('first');
    DB::transaction(fn () => $stage->publish(true));
    config()->set('concurrency.default', 'sync');
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    expect(VersionedStorage::get('report'))->toBe('second')
        ->and(VersionedStorage::versions('report'))->toBe([$stage->version]);
    Storage::disk('local')->assertMissing("report/{$first}");
});

it('retains failed upload tracking and the configured deadline during synchronous testing', function () {
    VersionedStorageTesting::runUploadsSynchronously();
    config()->set('laravel-versioned-storage.upload_timeout', 23);
    config()->set('laravel-versioned-storage.cleanup_grace', 17);
    $this->freezeTime();

    expect(fn () => VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        $files->put('partial', 'contents');

        throw new RuntimeException('upload failed');
    }))->toThrow(RuntimeException::class, 'upload failed');

    expect(VersionedStorage::directory('reports')->versions())->toBe([])
        ->and(DB::table('versioned_storage_stages')->value('state'))->toBe('uploading')
        ->and(DB::table('versioned_storage_stages')->value('cleanup_after'))
        ->toBe(now()->addSeconds(40)->ceilSecond()->toDateTimeString());
});

it('does not bypass metadata transaction restrictions during synchronous testing', function () {
    VersionedStorageTesting::runUploadsSynchronously();
    DB::beginTransaction();

    try {
        expect(fn () => VersionedStorage::put('report', 'contents'))
            ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction');
    } finally {
        DB::rollBack();
    }

    expect(DB::table('versioned_storage_stages')->count())->toBe(0);
});

it('can restore the actual runtime policy without changing the upload context', function () {
    $context = app(UploadContext::class);
    VersionedStorageTesting::runUploadsSynchronously();
    expect(app(UploadExecutionPolicy::class)->execution())->toBe(['timeout' => 60, 'fork' => false]);

    VersionedStorageTesting::restoreUploadExecution();

    expect(app(UploadExecutionPolicy::class))->toBe($context)
        ->and(app(UploadExecutionPolicy::class)->execution())->toBe(['timeout' => 60, 'fork' => true]);
});

it('does not carry synchronous execution into a fresh Laravel application', function () {
    VersionedStorageTesting::runUploadsSynchronously();
    $current = app();

    try {
        $application = new Application(TestPaths::root());
        $application->instance('config', new Repository);
        $application->register(VersionedStorageServiceProvider::class);

        expect($application->make(UploadExecutionPolicy::class))->toBe($application->make(UploadContext::class))
            ->and($application->make(UploadExecutionPolicy::class)->execution())->toBe(['timeout' => 60, 'fork' => true]);
    } finally {
        Application::setInstance($current);
    }

    expect(app(UploadExecutionPolicy::class)->execution())->toBe(['timeout' => 60, 'fork' => false]);
});

it('validates configured timeouts before writing in synchronous tests', function () {
    VersionedStorageTesting::runUploadsSynchronously();
    config()->set('laravel-versioned-storage.upload_timeout', 0);

    expect(fn () => VersionedStorage::put('report', 'contents'))
        ->toThrow(RuntimeException::class, 'positive number of seconds')
        ->and(DB::table('versioned_storage_stages')->count())->toBe(0);
});

it('rejects synchronous testing outside the Laravel test environment', function () {
    app()->instance('env', 'production');

    try {
        expect(fn () => VersionedStorageTesting::runUploadsSynchronously())
            ->toThrow(LogicException::class, 'only available while running tests');
    } finally {
        app()->instance('env', 'testing');
    }
});
