<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('laravel-versioned-storage.connection'));
        $schema->table('versioned_storage_stages', function (Blueprint $table): void {
            $table->boolean('failed')->default(false);
        });
        $schema->create('versioned_storage_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('version')->unique();
            $table->foreign('version')->references('version')->on('versioned_storage_stages')->cascadeOnDelete();
            $table->string('operation');
            $table->timestamp('cleanup_after')->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('laravel-versioned-storage.connection'));
        $schema->dropIfExists('versioned_storage_operations');
        $schema->table('versioned_storage_stages', function (Blueprint $table): void {
            $table->dropColumn('failed');
        });
    }
};
