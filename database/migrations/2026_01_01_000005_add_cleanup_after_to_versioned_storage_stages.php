<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $timeout = filter_var(config('laravel-versioned-storage.upload_timeout', 60), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $grace = filter_var(config('laravel-versioned-storage.cleanup_grace', 10800), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($timeout === false || $grace === false) {
            throw new RuntimeException('Upload timeout and cleanup grace must be valid durations before migrating.');
        }
        Schema::connection(config('laravel-versioned-storage.connection'))
            ->table('versioned_storage_stages', function (Blueprint $table): void {
                $table->timestamp('cleanup_after')->nullable();
                $table->index(['state', 'cleanup_after']);
            });
        DB::connection(config('laravel-versioned-storage.connection'))->table('versioned_storage_stages')
            ->update(['cleanup_after' => now()->addSeconds($timeout)->addSeconds($grace)]);
    }

    public function down(): void
    {
        Schema::connection(config('laravel-versioned-storage.connection'))
            ->table('versioned_storage_stages', function (Blueprint $table): void {
                $table->dropIndex(['state', 'cleanup_after']);
                $table->dropColumn('cleanup_after');
            });
    }
};
