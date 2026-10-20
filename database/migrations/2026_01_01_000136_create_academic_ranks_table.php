<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_ranks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('university_id')->nullable();
            $table->string('name_ar', 150);
            $table->string('name_en', 150);
            $table->enum('category', ['academic', 'administrative'])->default('academic');
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('is_active')->default(1);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('university_id')->references('id')->on('universities')->onDelete('cascade');
            $table->index(['university_id'], 'idx_academic_ranks_university');
            $table->index(['category'], 'idx_academic_ranks_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_ranks');
    }
};
