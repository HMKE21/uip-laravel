<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * course_video_unlocks: الطالب اللي كتب باسورد فيديو صح مرة مايتسألش عليه تاني.
 * pw_sig = sha1(password_hash) وقت الفتح — لو الدكتور غيّر الباسورد الـ sig بيتغيّر فالطالب يتطلب منه الباسورد الجديد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('course_video_unlocks')) {
            return;
        }
        Schema::create('course_video_unlocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('video_id');
            $table->unsignedBigInteger('student_id');
            $table->string('pw_sig', 40);
            $table->timestamps();
            $table->unique(['video_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_video_unlocks');
    }
};
