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
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('laravel-versioned-storage.connection'));
        $schema->dropIfExists('versioned_storage_versions');
        $schema->dropIfExists('versioned_storage_resources');
    }
};
