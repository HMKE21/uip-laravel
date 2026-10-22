<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول المحاضرات (Study Time Table): خانات أسبوعية متكررة لكل مادة — محاضرة / سكشن / معمل / تمرين،
 * بيوم ووقت وقاعة وعضو هيئة تدريس. بيتعمل من مديري المادة (مدرّس المادة، الكلية، الجامعة)
 * ويشوفه الطلاب المسجّلين في المادة. day_of_week: 1=الاثنين … 7=الأحد (ISO).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('course_sessions')) {
            Schema::create('course_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->index();
                // lecture | section | lab | tutorial
                $table->string('kind', 20)->default('lecture');
                $table->unsignedTinyInteger('day_of_week');
                $table->time('start_time');
                $table->time('end_time');
                $table->string('room', 120)->nullable();
                $table->string('group_label', 60)->nullable();           // مثال: "Section 2" / "مجموعة أ"
                $table->unsignedBigInteger('academic_staff_id')->nullable()->index();
                $table->date('starts_on')->nullable();                    // null = من أول الفصل
                $table->date('ends_on')->nullable();                      // null = لآخر الفصل
                $table->string('notes', 500)->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
                $table->index(['course_id', 'day_of_week']);
                $table->index(['day_of_week', 'room']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_sessions');
    }
};
