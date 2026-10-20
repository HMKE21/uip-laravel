<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug', 100);
            $table->string('module', 60);
            $table->string('description', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
