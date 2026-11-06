<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

it('keeps upload test commands disabled in ordinary Workbench sessions', function () {
    $process = new Process([PHP_BINARY, __DIR__.'/../../../vendor/bin/testbench', 'list', '--raw'], null, [
        'VERSIONED_STORAGE_TEST_DB' => false,
        'VERSIONED_STORAGE_TEST_ROOT' => false,
    ]);
    $process->setTimeout(15);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())->not->toContain('test:upload', 'test:enqueue-upload')
        ->and($process->getOutput())->toContain('laravel-versioned-storage:cleanup');
});

it('executes CLI uploads in a child and recovers timed-out uploads after their deadline', function (bool $slow) {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Real fork execution requires PCNTL and POSIX.');
    }
    $database = tempnam(sys_get_temp_dir(), 'versioned-timeout-');
    $disk = Storage::disk('local');
    config()->set('database.connections.uploads', [
        'driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true,
    ]);
    config()->set('laravel-versioned-storage.connection', 'uploads');

    try {
        foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $path) {
            (require $path)->up();
        }
        $process = new Process([PHP_BINARY, __DIR__.'/../../../vendor/bin/testbench', 'test:upload'], null, [
            'VERSIONED_STORAGE_TEST_DB' => $database,
            'VERSIONED_STORAGE_TEST_ROOT' => $disk->path(''),
            'VERSIONED_STORAGE_TEST_SLOW' => $slow ? '1' : '0',
        ]);
        $process->setTimeout(15);
        $process->run();
        $stage = DB::connection('uploads')->table('versioned_storage_stages')->first();
        expect($stage)->not->toBeNull();

        if ($slow) {
            expect($process->isSuccessful())->toBeFalse($process->getOutput().$process->getErrorOutput())
                ->and($stage->state)->toBe('uploading')
                ->and(VersionedStorage::directory('forked')->versions())->toBe([]);
            $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
            expect(DB::connection('uploads')->table('versioned_storage_stages')->count())->toBe(1);
            $this->travelTo(Carbon::parse($stage->cleanup_after));
            $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
            expect(DB::connection('uploads')->table('versioned_storage_stages')->count())->toBe(0);
            $disk->assertMissing('forked/'.$stage->version);
        } else {
            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
            preg_match('/parent-pid: (\d+)/', $process->getOutput(), $parent);
            expect($parent)->toHaveCount(2);
            expect($stage->state)->toBe('published')
                ->and(VersionedStorage::directory('forked')->get('partial'))->not->toBe($parent[1])
                ->and(VersionedStorage::directory('forked')->get('finished'))->toBe('contents');
            $file = new Process([PHP_BINARY, __DIR__.'/../../../vendor/bin/testbench', 'test:upload-file'], null, [
                'VERSIONED_STORAGE_TEST_DB' => $database,
                'VERSIONED_STORAGE_TEST_ROOT' => $disk->path(''),
            ]);
            $file->setTimeout(15);
            $file->run();
            expect($file->isSuccessful())->toBeTrue($file->getOutput().$file->getErrorOutput())
                ->and(VersionedStorage::get('forked-file'))->toBe('contents')
                ->and(VersionedStorage::directory('retained-connection')->get('finished'))->toBe('original connection');
        }
    } finally {
        config()->set('laravel-versioned-storage.connection', null);
        DB::purge('uploads');
        unlink($database);
    }
})->with([false, true]);

it('uses actual worker and job limits and retains a killed job upload until cleanup is due', function (string $limit, bool $slow) {
    if (! function_exists('pcntl_alarm') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Worker hard timeouts require PCNTL and POSIX.');
    }
    $database = tempnam(sys_get_temp_dir(), 'versioned-queue-timeout-');
    $disk = Storage::disk('local');
    config()->set('database.connections.uploads', [
        'driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true,
    ]);
    config()->set('laravel-versioned-storage.connection', 'uploads');
    $environment = [
        'VERSIONED_STORAGE_TEST_DB' => $database, 'VERSIONED_STORAGE_TEST_ROOT' => $disk->path(''),
        'VERSIONED_STORAGE_TEST_NOW' => now()->toDateTimeString(),
    ];

    try {
        foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $path) {
            (require $path)->up();
        }
        $enqueue = new Process([PHP_BINARY, __DIR__.'/../../../vendor/bin/testbench', 'test:enqueue-upload', $limit, $slow ? 'slow' : 'fast'], null, $environment);
        $enqueue->setTimeout(15);
        $enqueue->run();
        expect($enqueue->isSuccessful())->toBeTrue($enqueue->getOutput().$enqueue->getErrorOutput());
        $process = new Process([
            PHP_BINARY, __DIR__.'/../../../vendor/bin/testbench',
            'queue:work', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout='.($limit === 'job' ? '10' : '1'),
        ], null, $environment);
        $process->setTimeout(15);

        if ($slow) {
            expect(fn () => $process->run())->toThrow(ProcessSignaledException::class)
                ->and($process->getTermSignal())->toBe(SIGKILL);
        } else {
            $process->run();
        }
        $stage = DB::connection('uploads')->table('versioned_storage_stages')->first();
        expect($stage)->not->toBeNull($process->getOutput().$process->getErrorOutput());

        if ($slow) {
            expect($process->isSuccessful())->toBeFalse($process->getOutput().$process->getErrorOutput())
                ->and($stage->state)->toBe('uploading')
                ->and(VersionedStorage::directory('queued')->versions())->toBe([]);
            $disk->assertMissing('queued/'.$stage->version.'/finished');
        } else {
            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
                ->and($stage->state)->toBe('published')
                ->and(VersionedStorage::directory('queued')->get('finished'))->toBe('contents');
        }
        // A job override of one second must beat the worker's ten-second limit.
        expect($stage->cleanup_after)->toBe(Carbon::parse($environment['VERSIONED_STORAGE_TEST_NOW'])->addSeconds(10801)->toDateTimeString());
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        expect(DB::connection('uploads')->table('versioned_storage_stages')->count())->toBe(1);
        $this->travelTo(Carbon::parse($stage->cleanup_after));
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        expect(DB::connection('uploads')->table('versioned_storage_stages')->count())->toBe($slow ? 0 : 1);

        if ($slow) {
            $disk->assertMissing('queued/'.$stage->version);
        }
    } finally {
        config()->set('laravel-versioned-storage.connection', null);
        DB::purge('uploads');
        unlink($database);
    }
})->with(['job', 'worker'])->with([false, true]);

it('forks uploads served by the real PHP development server', function (bool $slow) {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Development server uploads require PCNTL and POSIX.');
    }
    $database = tempnam(sys_get_temp_dir(), 'versioned-server-timeout-');
    $disk = Storage::disk('local');
    config()->set('database.connections.uploads', [
        'driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true,
    ]);
    config()->set('laravel-versioned-storage.connection', 'uploads');
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    expect($socket)->not->toBeFalse();
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = new Process([PHP_BINARY, '-S', $address, __DIR__.'/../../Support/upload-timeout/http.php'], null, [
        'VERSIONED_STORAGE_TEST_DB' => $database, 'VERSIONED_STORAGE_TEST_ROOT' => $disk->path(''),
        'VERSIONED_STORAGE_TEST_SLOW' => $slow ? '1' : '0',
    ]);
    $server->setTimeout(15);

    try {
        foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $path) {
            (require $path)->up();
        }
        $server->start();
        expect($server->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'started')))->toBeTrue();
        $response = file_get_contents('http://'.$address.'/upload', context: stream_context_create([
            'http' => ['timeout' => 10, 'ignore_errors' => true],
        ]));
        expect($response)->not->toBeFalse();
        $stage = DB::connection('uploads')->table('versioned_storage_stages')->sole();
        expect($stage->state)->toBe($slow ? 'uploading' : 'published');

        if ($slow) {
            expect($response)->toContain('timed out or exited abnormally');
            $disk->assertMissing('forked/'.$stage->version.'/finished');
        } else {
            expect($response)->toContain('parent-pid:');
            preg_match('/parent-pid: (\d+)/', $response, $parent);
            expect($parent)->toHaveCount(2)
                ->and($response)->toContain('published')
                ->and(VersionedStorage::directory('forked')->get('partial'))->not->toBe($parent[1]);
        }
    } finally {
        $server->stop();
        config()->set('laravel-versioned-storage.connection', null);
        DB::purge('uploads');
        unlink($database);
    }
})->with([false, true]);
