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
        $connection = config('laravel-versioned-storage.connection');

        Schema::connection($connection)->create('versioned_storage_reservations', function (Blueprint $table): void {
            $table->uuid('version')->primary();
        });

        DB::connection($connection)->table('versioned_storage_reservations')->insertUsing(
            ['version'],
            DB::connection($connection)->table('versioned_storage_versions')->select('version'),
        );
    }

    public function down(): void
    {
        Schema::connection(config('laravel-versioned-storage.connection'))
            ->dropIfExists('versioned_storage_reservations');
    }
};
