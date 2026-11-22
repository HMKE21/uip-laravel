<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مكتبة فيديوهات الشرح للمادة:
 *  - course_videos       : فيديو واحد (ملف خاص) + باسورد اختياري (hash) + ترتيب + حالة draft|published.
 *  - course_video_views  : تقدّم كل طالب في كل فيديو: الأجزاء اللي شافها فعلًا (ranges مدموجة بالثواني) → watched_seconds / percent / completed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('course_videos')) {
            Schema::create('course_videos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->index();
                $table->string('title', 200);
                $table->text('description')->nullable();
                $table->string('file_path', 500);
                $table->string('file_name', 255)->nullable();
                $table->string('file_mime', 120)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->string('password_hash', 255)->nullable();      // null = من غير باسورد
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('status', 10)->default('published');    // draft | published
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
                $table->index(['course_id', 'status', 'sort_order']);
            });
        }

        if (!Schema::hasTable('course_video_views')) {
            Schema::create('course_video_views', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('video_id');
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->mediumText('ranges')->nullable();               // JSON [[start,end],...] بالثواني، مدموجة
                $table->unsignedInteger('watched_seconds')->default(0);
                $table->decimal('percent', 5, 2)->default(0);
                $table->unsignedInteger('last_position')->default(0);
                $table->unsignedInteger('max_position')->default(0);
                $table->unsignedInteger('play_count')->default(0);
                $table->boolean('completed')->default(false);
                $table->dateTime('first_watched_at')->nullable();
                $table->dateTime('last_watched_at')->nullable();
                $table->timestamps();
                $table->unique(['video_id', 'student_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_video_views');
        Schema::dropIfExists('course_videos');
    }
};
