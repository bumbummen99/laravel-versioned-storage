<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\UploadScenario;

if (getenv('VERSIONED_STORAGE_TEST_DB') !== false) {
    Route::get('/health', fn (): array => [
        'sapi' => PHP_SAPI,
        'pid' => getmypid(),
        'max_execution_time' => (int) ini_get('max_execution_time'),
    ]);

    Route::get('/upload', function () {
        ignore_user_abort(true);

        try {
            return response(UploadScenario::run(), headers: ['Content-Type' => 'text/plain']);
        } catch (RuntimeException $exception) {
            report($exception);

            return response($exception->getMessage(), 500);
        }
    });
}

Route::get('/', function () {
    return view('welcome');
});
