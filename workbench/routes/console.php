<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use Workbench\App\Jobs\TimeoutUploadJob;
use Workbench\App\UploadScenario;

if (getenv('VERSIONED_STORAGE_TEST_DB') === false) {
    return;
}

Artisan::command('test:upload', function (): void {
    $this->output->write(UploadScenario::run());
});

Artisan::command('test:enqueue-upload {limit} {speed}', function (): void {
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Queue::push(new TimeoutUploadJob($this->argument('limit') === 'job' ? 1 : null, $this->argument('speed') === 'slow'));
});

Artisan::command('test:upload-file', function (): int {
    VersionedStorage::put('forked-file', 'contents');

    if (Artisan::call('laravel-versioned-storage:cleanup') !== 0) {
        return 1;
    }
    $handler = VersionedStorage::directory('retained-connection');
    config()->set('laravel-versioned-storage.connection', 'unconfigured');
    $stage = $handler->stage(function (FilesystemAdapter $files): void {
        $files->put('finished', 'original connection');
    });
    $stage->publish();
    config()->set('laravel-versioned-storage.connection', null);

    return Artisan::call('laravel-versioned-storage:cleanup');
});
