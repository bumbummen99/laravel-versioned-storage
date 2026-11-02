<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected const TABLE_NAME = 'versioned_storage_entry';

    public function up()
    {
        Schema::create(static::TABLE_NAME, function (Blueprint $table) {
            $table->id();

            $table->string('disk');
            $table->string('path');

            $table->string('version');
            $table->boolean('is_current')
                ->default(false);

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::drop(static::TABLE_NAME);
    }
};
