<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('university_id');
            $table->unsignedBigInteger('author_user_id');
            $table->enum('category', ['academic', 'event', 'competition', 'deadline', 'training', 'workshop', 'research'])->default('academic');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->dateTime('publish_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->string('deleted_reason', 255)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->unsignedBigInteger('target_faculty_id')->nullable();
            $table->unsignedBigInteger('target_department_id')->nullable();
            $table->unsignedTinyInteger('target_academic_year')->nullable();
            $table->foreign('university_id')->references('id')->on('universities')->onDelete('cascade');
            $table->foreign('author_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('target_faculty_id')->references('id')->on('faculties')->onDelete('set null');
            $table->foreign('target_department_id')->references('id')->on('departments')->onDelete('set null');
            $table->index(['university_id', 'deleted_at', 'category', 'publish_at'], 'idx_announcements_university');
            $table->index(['expires_at'], 'idx_announcements_expires');
            $table->index(['target_faculty_id', 'target_department_id', 'target_academic_year'], 'idx_announcements_target');
            $table->fullText(['title', 'body'], 'ft_announcements_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
