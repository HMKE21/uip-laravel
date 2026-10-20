<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفتر درجات المقرر (Course gradebook): بيربط امتحانات المقرر (exams.course_id) بدرجة المقرر النهائية.
 * كل صف = امتحان محسوب في درجة المقرر بوزن معيّن (% من درجة المقرر)، وسياسة اختيار المحاولة لو الطالب له أكتر من محاولة.
 * مفيش FK صريح (زي باقي جداول المقررات) — الحسابات بتعمل join على exams.course_id فأي امتحان اتفصل عن المقرر بيتجاهل لوحده.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('course_grade_items')) {
            Schema::create('course_grade_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('exam_id')->index();
                $table->decimal('weight', 5, 2);                         // % من درجة المقرر (0 < weight <= 100)
                $table->string('attempt_policy', 10)->default('highest'); // highest | latest | first
                $table->timestamps();
                $table->unique(['course_id', 'exam_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_grade_items');
    }
};
