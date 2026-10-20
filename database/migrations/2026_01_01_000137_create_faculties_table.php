<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('university_id');
            $table->string('name_ar', 200);
            $table->string('name_en', 200);
            $table->string('slug', 220);
            $table->text('description')->nullable();
            $table->text('mission')->nullable();
            $table->text('vision')->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('cover_path', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('is_public')->default(1);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('university_id')->references('id')->on('universities')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->unique(['university_id', 'slug'], 'uq_faculties_university_slug');
            $table->unique(['user_id']);
            $table->index(['university_id'], 'idx_faculties_university');
            $table->index(['status'], 'idx_faculties_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculties');
    }
};
