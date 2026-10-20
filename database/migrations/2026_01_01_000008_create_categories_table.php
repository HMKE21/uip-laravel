<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120);
            $table->string('name_en', 150);
            $table->string('name_ar', 150);
            $table->string('icon', 40)->default('folder');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(1);
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['slug']);
            $table->index(['is_active', 'sort_order'], 'idx_categories_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
