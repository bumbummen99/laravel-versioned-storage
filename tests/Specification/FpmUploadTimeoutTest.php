<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\FpmServer;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use Symfony\Component\Process\ExecutableFinder;

it('verifies real FPM upload safety across PHP, FPM and Nginx timeout settings', function (
    string $workload, int $execution, int $termination, int $read, int $status, string $outcome,
) {
    $finder = new ExecutableFinder;
    $fpm = $finder->find('php-fpm'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, extraDirs: ['/usr/sbin'])
        ?? $finder->find('php-fpm', extraDirs: ['/usr/sbin']);
    $nginx = $finder->find('nginx', extraDirs: ['/usr/sbin']);

    if (PHP_OS_FAMILY === 'Windows' || $fpm === null || $nginx === null) {
        $this->markTestSkipped('Real FPM timeout tests require PHP-FPM and Nginx on Unix.');
    }

    $server = new FpmServer;
    $database = $server->directory.'/database.sqlite';
    touch($database);
    $disk = Storage::disk('local');
    config()->set('database.connections.uploads', [
        'driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true,
    ]);
    config()->set('laravel-versioned-storage.connection', 'uploads');
    $this->freezeTime();
    $timeout = $termination ?: 10;

    try {
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $path) {
            (require $path)->up();
        }
        $server->start($fpm, $nginx, $execution, $termination, $read, [
            'VERSIONED_STORAGE_TEST_DB' => $database,
            'VERSIONED_STORAGE_TEST_ROOT' => $disk->path(''),
            'VERSIONED_STORAGE_TEST_NOW' => now()->toDateTimeString(),
            'VERSIONED_STORAGE_TEST_TIMEOUT' => (string) $timeout,
            'VERSIONED_STORAGE_TEST_WORKLOAD' => $workload,
        ]);
        $health = $server->request('/health')->throw()->json();
        expect($health['sapi'])->toBe('fpm-fcgi')
            ->and($health['max_execution_time'])->toBe($execution);

        $response = $server->request('/upload');
        expect($response->status())->toBe($status, $server->logs());
        $stage = DB::connection('uploads')->table('versioned_storage_stages')->sole();
        $prefix = 'fpm/'.$stage->version;
        expect($stage->cleanup_after)->toBe(now()->addSeconds($timeout + 10800)->toDateTimeString())
            ->and($disk->get($prefix.'/partial'))->toBe((string) $health['pid']);

        if ($status === 504) {
            expect($server->logs())->toContain('upstream timed out')
                ->and($stage->state)->toBe('uploading');
            $disk->assertMissing($prefix.'/finished');
        } elseif ($status === 200) {
            expect($response->body())->toContain('published');
        }

        // A 504 is not cancellation: the intent and partial upload must still survive cleanup.
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        expect(DB::connection('uploads')->table('versioned_storage_stages')->count())->toBe(1);
        $disk->assertExists($prefix.'/partial');

        if ($outcome === 'terminated') {
            $server->waitFor(fn (): bool => str_contains($server->logs(), 'execution timed out'));
            expect($server->logs())->toContain('terminating');
            expect($server->request('/health')->throw()->json('pid'))->not->toBe($health['pid']);
        } elseif ($outcome === 'php-timeout') {
            expect($server->logs())->toContain('Maximum execution time of 1 second exceeded');
        } else {
            $server->waitFor(fn (): bool => DB::connection('uploads')->table('versioned_storage_stages')->value('state') === 'published');
        }

        $stage = DB::connection('uploads')->table('versioned_storage_stages')->sole();
        $completed = $outcome === 'published';
        expect($stage->state)->toBe($completed ? 'published' : 'uploading')
            ->and(VersionedStorage::directory('fpm')->versions())->toBe($completed ? [$stage->version] : []);

        if ($completed) {
            $disk->assertExists($prefix.'/finished');
        } else {
            $disk->assertMissing($prefix.'/finished');
        }

        $deadline = Carbon::parse($stage->cleanup_after);
        $this->travelTo($deadline->copy()->subSecond());
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        expect(DB::connection('uploads')->table('versioned_storage_stages')->count())->toBe(1);
        $disk->assertExists($prefix.'/partial');
        $this->travelTo($deadline);
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        expect(DB::connection('uploads')->table('versioned_storage_stages')->count())->toBe($completed ? 1 : 0);

        if ($completed) {
            expect(VersionedStorage::directory('fpm')->get('finished'))->toBe('contents');
        } else {
            $disk->assertMissing($prefix);
        }
    } finally {
        config()->set('laravel-versioned-storage.connection', null);
        DB::purge('uploads');
        $server->stop();
    }
})->with([
    'normal upload' => ['fast', 1, 4, 10, 200, 'published'],
    'PHP stops CPU-bound work' => ['cpu', 1, 6, 10, 500, 'php-timeout'],
    'FPM stops blocked work despite PHP limit' => ['blocked', 1, 2, 10, 502, 'terminated'],
    'Nginx times out before FPM stops blocked work' => ['blocked', 1, 4, 1, 504, 'terminated'],
    'Nginx and PHP limits alone do not stop blocked work' => ['blocked', 1, 0, 1, 504, 'published'],
])->group('fpm');
