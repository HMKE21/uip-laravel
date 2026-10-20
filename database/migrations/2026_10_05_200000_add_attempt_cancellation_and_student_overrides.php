<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam & Assessment System — إدارة محاولات الطلاب (إلغاء محاولة / إعادة امتحان).
 *
 * 1) exam_attempts: cancelled_at / cancelled_by (user id للمدرس) / cancel_reason.
 *    status='cancelled' كان موجود في الـ enum من البداية بس مفيش حد بيكتبه.
 *    المحاولة الملغاة بتفضل في السجل (مش بتتمسح) وبتتحسب ضمن عدد المحاولات
 *    المستخدمة، إلا لو المدرس اختار "إلغاء + السماح بإعادة" (بيزوّد extra_attempts).
 *
 * 2) exam_student_overrides: استثناء لطالب واحد على امتحان واحد:
 *    - extra_attempts: محاولات إضافية فوق exams.max_attempts (إعادة الامتحان).
 *    - available_until: لو متحدد، الطالب ده بس يقدر يبدأ الامتحان لحد الوقت ده
 *      حتى لو نافذة الامتحان (start_at/end_at) أو حالته (closed) خلصت.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            if (!Schema::hasColumn('exam_attempts', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            if (!Schema::hasColumn('exam_attempts', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable();
            }
            if (!Schema::hasColumn('exam_attempts', 'cancel_reason')) {
                $table->text('cancel_reason')->nullable();
            }
        });

        if (!Schema::hasTable('exam_student_overrides')) {
            Schema::create('exam_student_overrides', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('exam_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedSmallInteger('extra_attempts')->default(0);
                $table->timestamp('available_until')->nullable();
                $table->unsignedBigInteger('granted_by')->nullable();
                $table->text('reason')->nullable();
                $table->timestamps();

                $table->unique(['exam_id', 'student_id']);
                $table->index('student_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_student_overrides');

        Schema::table('exam_attempts', function (Blueprint $table) {
            foreach (['cancelled_at', 'cancelled_by', 'cancel_reason'] as $col) {
                if (Schema::hasColumn('exam_attempts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
