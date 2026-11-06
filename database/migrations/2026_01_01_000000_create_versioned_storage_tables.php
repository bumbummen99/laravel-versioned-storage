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

        $schema->create('versioned_storage_resources', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('disk');
            $table->text('path');
            $table->boolean('is_directory');
            $table->uuid('current_version')->nullable();
        });

        $schema->create('versioned_storage_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('versioned_storage_resources');
            $table->uuid('version')->unique();
            $table->timestamp('created_at');
            $table->boolean('pending_delete')->default(false);
        });

        $schema->create('versioned_storage_locks', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
        });

        $schema->create('versioned_storage_reservations', function (Blueprint $table): void {
            $table->uuid('version')->primary();
        });

        $schema->create('versioned_storage_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('versioned_storage_resources');
            $table->uuid('version')->unique();
            $table->string('state')->index();
            $table->timestamp('cleanup_after')->nullable();
            $table->index(['state', 'cleanup_after']);
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
        $schema->dropIfExists('versioned_storage_stages');
        $schema->dropIfExists('versioned_storage_reservations');
        $schema->dropIfExists('versioned_storage_locks');
        $schema->dropIfExists('versioned_storage_versions');
        $schema->dropIfExists('versioned_storage_resources');
    }
};
