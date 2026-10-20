<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug', 50);
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('description', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->string('portal_prefix', 30)->nullable();
            $table->boolean('is_system')->default(0);
            $table->unique(['slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
