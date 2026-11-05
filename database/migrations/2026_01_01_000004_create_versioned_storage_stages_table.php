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
            ->create('versioned_storage_stages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('resource_id')->constrained('versioned_storage_resources');
                $table->uuid('version')->unique();
                $table->string('state')->index();
            });
    }

    public function down(): void
    {
        Schema::connection(config('laravel-versioned-storage.connection'))->dropIfExists('versioned_storage_stages');
    }
};
