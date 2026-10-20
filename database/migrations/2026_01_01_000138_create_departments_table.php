<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('faculty_id');
            $table->string('name_ar', 200);
            $table->string('name_en', 200);
            $table->string('slug', 220);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive', 'pending'])->default('active');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->boolean('is_public')->default(1);
            $table->foreign('faculty_id')->references('id')->on('faculties')->onDelete('cascade');
            $table->unique(['faculty_id', 'slug'], 'uq_departments_faculty_slug');
            $table->index(['faculty_id'], 'idx_departments_faculty');
            $table->index(['status'], 'idx_departments_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
