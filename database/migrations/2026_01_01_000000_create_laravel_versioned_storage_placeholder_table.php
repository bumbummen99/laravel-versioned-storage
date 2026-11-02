<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versioned_storage_placeholder', function (Blueprint $table) {
            $table->id();

            $table->string('disk');
            $table->string('path', 80);

            $table->string('version', 36);
            $table->boolean('is_current')
                ->default(false);

            $table->timestamps();

            $table->unique(['disk', 'path', 'version']);
            $table->index(['disk', 'path', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versioned_storage_placeholder');
    }
};
