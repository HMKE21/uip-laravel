<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('university_id');
            $table->unsignedBigInteger('author_user_id');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->boolean('is_event')->default(0);
            $table->dateTime('event_starts_at')->nullable();
            $table->dateTime('event_ends_at')->nullable();
            $table->string('event_location', 255)->nullable();
            $table->boolean('is_pinned')->default(0);
            $table->dateTime('pinned_at')->nullable();
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);
            $table->unsignedInteger('saves_count')->default(0);
            $table->dateTime('deleted_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->string('deleted_reason', 255)->nullable();
            $table->boolean('status')->default(1);
            $table->boolean('is_edited')->default(0);
            $table->dateTime('edited_at')->nullable();
            $table->unsignedBigInteger('target_faculty_id')->nullable();
            $table->unsignedBigInteger('target_department_id')->nullable();
            $table->unsignedTinyInteger('target_academic_year')->nullable();
            $table->foreign('university_id')->references('id')->on('universities')->onDelete('cascade');
            $table->foreign('author_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('target_faculty_id')->references('id')->on('faculties')->onDelete('set null');
            $table->foreign('target_department_id')->references('id')->on('departments')->onDelete('set null');
            $table->index(['university_id', 'deleted_at', 'is_pinned', 'created_at'], 'idx_feed_posts_university');
            $table->index(['status'], 'idx_feed_posts_status');
            $table->index(['target_faculty_id', 'target_department_id', 'target_academic_year'], 'idx_feed_posts_target');
            $table->fullText(['title', 'body'], 'ft_feed_posts_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_posts');
    }
};
