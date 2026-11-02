<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create(config('versioned-storage.table', 'versioned_storage_entry'), function (Blueprint $table) {
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

    public function down()
    {
        Schema::dropIfExists(config('versioned-storage.table', 'versioned_storage_entry'));
    }
};
