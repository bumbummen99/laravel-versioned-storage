<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('laravel-versioned-storage.connection'))
            ->table('versioned_storage_versions', function (Blueprint $table): void {
                $table->boolean('pending_delete')->default(false);
            });
    }

    public function down(): void
    {
        Schema::connection(config('laravel-versioned-storage.connection'))
            ->table('versioned_storage_versions', function (Blueprint $table): void {
                $table->dropColumn('pending_delete');
            });
    }
};
