<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * غلاف (صورة مصغّرة) + أبعاد الفيديو، عشان القائمة تبان احترافية من غير ما الطالب يفتح الفيديو.
 *  - thumbnail_path : مسار نسبي لصورة الغلاف (ملف خاص تحت uploads/course-videos/covers).
 *  - width/height   : أبعاد الفيديو (بتحدد شكل المشغّل: أفقي / رأسي).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('course_videos')) {
            return;
        }
        Schema::table('course_videos', function (Blueprint $table) {
            if (!Schema::hasColumn('course_videos', 'thumbnail_path')) {
                $table->string('thumbnail_path', 500)->nullable()->after('file_size');
            }
            if (!Schema::hasColumn('course_videos', 'width')) {
                $table->unsignedSmallInteger('width')->nullable()->after('thumbnail_path');
            }
            if (!Schema::hasColumn('course_videos', 'height')) {
                $table->unsignedSmallInteger('height')->nullable()->after('width');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('course_videos')) {
            return;
        }
        Schema::table('course_videos', function (Blueprint $table) {
            foreach (['thumbnail_path', 'width', 'height'] as $c) {
                if (Schema::hasColumn('course_videos', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
