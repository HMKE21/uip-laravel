<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam & Assessment System — سياسة التسليم المتأخر + الوقت الإضافي لمحاولة.
 *
 * exams (الدكتور بيحددها وقت إنشاء/تعديل الامتحان):
 *  - late_grace_minutes   : دقايق بعد انتهاء الوقت يقدر الطالب فيها لسه يسلّم (0 = الامتحان بيقفل فجأة زي الأول).
 *  - late_penalty_percent : نسبة بتتخصم من الدرجة النهائية لو التسليم حصل جوه فترة السماح (0 = من غير خصم).
 *
 * exam_attempts:
 *  - extra_time_minutes   : مجموع الدقايق الإضافية اللي المدرس منحها للمحاولة دي (متضافة فعلًا على expires_at).
 *  - is_late              : المحاولة اتسلّمت بعد موعدها (المعدّل) وجوه فترة السماح.
 *  - late_penalty_percent : snapshot للخصم وقت التسليم (تعديل الامتحان بعد كده عمره ما يغيّر التاريخ).
 *  - score_before_penalty : الدرجة الخام قبل الخصم (null لو مفيش خصم اتطبّق).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (!Schema::hasColumn('exams', 'late_grace_minutes')) {
                $table->unsignedSmallInteger('late_grace_minutes')->default(0);
            }
            if (!Schema::hasColumn('exams', 'late_penalty_percent')) {
                $table->decimal('late_penalty_percent', 5, 2)->default(0);
            }
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            if (!Schema::hasColumn('exam_attempts', 'extra_time_minutes')) {
                $table->unsignedSmallInteger('extra_time_minutes')->default(0);
            }
            if (!Schema::hasColumn('exam_attempts', 'is_late')) {
                $table->boolean('is_late')->default(false);
            }
            if (!Schema::hasColumn('exam_attempts', 'late_penalty_percent')) {
                $table->decimal('late_penalty_percent', 5, 2)->nullable();
            }
            if (!Schema::hasColumn('exam_attempts', 'score_before_penalty')) {
                $table->decimal('score_before_penalty', 6, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            foreach (['extra_time_minutes', 'is_late', 'late_penalty_percent', 'score_before_penalty'] as $col) {
                if (Schema::hasColumn('exam_attempts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('exams', function (Blueprint $table) {
            foreach (['late_grace_minutes', 'late_penalty_percent'] as $col) {
                if (Schema::hasColumn('exams', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
