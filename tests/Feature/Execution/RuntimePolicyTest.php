<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Policies\UploadContext;
use SkyRaptor\LaravelVersionedStorage\Testing\VersionedStorageTesting;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function uploadContextForSapi(string $sapi): UploadContext
{
    VersionedStorageTesting::restoreUploadExecution();
    $context = new class($sapi) extends UploadContext
    {
        public function __construct(private readonly string $runtime)
        {
            parent::__construct(app(Repository::class));
        }

        protected function sapi(): string
        {
            return $this->runtime;
        }
    };
    app()->instance(UploadContext::class, $context);

    return $context;
}

it('uses the configured externally enforced FPM timeout and persists its cleanup deadline', function () {
    $context = uploadContextForSapi('fpm-fcgi');
    config()->set('laravel-versioned-storage.upload_timeout', 60);
    config()->set('laravel-versioned-storage.cleanup_grace', 10800);
    $this->freezeTime();

    expect($context->execution())->toBe(['timeout' => 60, 'fork' => false]);
    $stage = VersionedStorage::stage('report', 'contents');
    expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('cleanup_after'))
        ->toBe(now()->addSeconds(10860)->ceilSecond()->toDateTimeString());
});

it('wires processed jobs to the worker fallback and clears their context', function () {
    if (! function_exists('pcntl_alarm') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Daemon timeout enforcement requires PCNTL and POSIX.');
    }
    $context = uploadContextForSapi('cli');
    $input = new ArrayInput(['--timeout' => 120]);
    Event::dispatch(new CommandStarting('queue:work', $input, new NullOutput));
    $job = Mockery::mock(RedisJob::class);
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('timeout')->andReturn(null);
    Event::dispatch(new JobProcessing('redis', $job));

    expect($context->execution())->toBe(['timeout' => 120, 'fork' => false]);
    Event::dispatch(new JobProcessed('redis', $job));
    expect($context->execution())->toBe(['timeout' => 60, 'fork' => true]);
    Event::dispatch(new CommandFinished('queue:work', $input, new NullOutput, 0));
});

it('wires failed jobs to their timeout override and clears their context', function () {
    if (! function_exists('pcntl_alarm') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Daemon timeout enforcement requires PCNTL and POSIX.');
    }
    $context = uploadContextForSapi('cli');
    $input = new ArrayInput(['--timeout' => 120]);
    Event::dispatch(new CommandStarting('queue:work', $input, new NullOutput));
    $job = Mockery::mock(RedisJob::class);
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('timeout')->andReturn(45);
    Event::dispatch(new JobProcessing('redis', $job));

    expect($context->execution())->toBe(['timeout' => 45, 'fork' => false]);
    Event::dispatch(new JobExceptionOccurred('redis', $job, new RuntimeException('job failed')));
    expect($context->execution())->toBe(['timeout' => 60, 'fork' => true]);
    Event::dispatch(new CommandFinished('queue:work', $input, new NullOutput, 0));
});

it('rejects unbounded worker timeouts instead of creating unsafe upload intents', function () {
    uploadContextForSapi('cli');
    Event::dispatch(new CommandStarting('queue:work', new ArrayInput(['--timeout' => 0]), new NullOutput));
    $job = Mockery::mock(RedisJob::class);
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('timeout')->andReturn(null);
    Event::dispatch(new JobProcessing('redis', $job));
    expect(fn () => VersionedStorage::put('report', 'contents'))->toThrow(RuntimeException::class, 'positive')
        ->and(DB::table('versioned_storage_stages')->count())->toBe(0);
});

it('retains a failed upload journal when a simulated remote write completes after immediate cleanup', function () {
    uploadContextForSapi('fpm-fcgi');
    $this->freezeTime();
    expect(fn () => VersionedStorage::stageDirectory('late-upload', function (): void {
        throw new RuntimeException('Client stopped waiting for storage.');
    }))->toThrow(RuntimeException::class, 'Client stopped waiting');
    $stage = DB::table('versioned_storage_stages')->sole();
    $disk = Storage::disk('local');
    $disk->assertMissing('late-upload/'.$stage->version);
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->count())->toBe(1);

    $disk->put('late-upload/'.$stage->version.'/delayed', 'Remote service completed after the client stopped.');
    $this->travelTo(Carbon::parse($stage->cleanup_after)->subSecond());
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    $disk->assertExists('late-upload/'.$stage->version.'/delayed');
    expect(DB::table('versioned_storage_stages')->count())->toBe(1);
    $this->travel(1)->seconds();
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    $disk->assertMissing('late-upload/'.$stage->version);
    expect(DB::table('versioned_storage_stages')->count())->toBe(0);
});

it('forks one-shot workers because Laravel does not arm their daemon alarm', function () {
    $context = uploadContextForSapi('cli');
    Event::dispatch(new CommandStarting('queue:work', new ArrayInput(['--timeout' => 37, '--once' => true]), new NullOutput));
    $job = Mockery::mock(RedisJob::class);
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('timeout')->andReturn(null);
    Event::dispatch(new JobProcessing('redis', $job));
    expect($context->execution())->toBe(['timeout' => 37, 'fork' => true]);
});

it('rejects unknown web runtimes before committing an upload intent', function () {
    uploadContextForSapi('apache2handler');
    expect(fn () => VersionedStorage::stage('report', 'contents'))->toThrow(RuntimeException::class, 'Uploads require PHP-FPM')
        ->and(DB::table('versioned_storage_stages')->count())->toBe(0);
});

it('rejects invalid upload limits and grace durations before committing an intent', function (string $key, mixed $value) {
    uploadContextForSapi('fpm-fcgi');
    config()->set('laravel-versioned-storage.'.$key, $value);
    expect(fn () => VersionedStorage::stage('report', 'contents'))->toThrow(RuntimeException::class)
        ->and(DB::table('versioned_storage_stages')->count())->toBe(0);
})->with([
    ['upload_timeout', 0],
    ['cleanup_grace', -1], ['cleanup_grace', 'invalid'],
]);

it('retains incomplete upload tracking until the stored deadline regardless of later configuration', function () {
    uploadContextForSapi('fpm-fcgi');
    $this->freezeTime();
    $version = VersionedStorage::put('report', 'contents');
    DB::table('versioned_storage_versions')->where('version', $version)->delete();
    DB::table('versioned_storage_resources')->update(['current_version' => null]);
    DB::table('versioned_storage_stages')->update(['state' => 'uploading']);
    $deadline = Carbon::parse(DB::table('versioned_storage_stages')->where('version', $version)->value('cleanup_after'));
    config()->set('laravel-versioned-storage.upload_timeout', 1);
    config()->set('laravel-versioned-storage.cleanup_grace', 0);

    $this->travelTo($deadline->copy()->subSecond());
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->where('version', $version)->exists())->toBeTrue();
    $this->travel(1)->seconds();
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_stages')->where('version', $version)->exists())->toBeFalse()
        ->and(VersionedStorage::exists('report', $version))->toBeFalse();
});
