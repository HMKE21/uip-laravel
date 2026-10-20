<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تظلم الطالب على الدرجة.
 *
 * exams: appeals_enabled (الافتراضي شغال) + appeal_window_days (أيام التظلم من ظهور النتيجة للطالب).
 * exam_grade_appeals: exam_question_id = null يعني تظلم على الدرجة الكلية، غير كده على سؤال بعينه.
 * status: pending / accepted / rejected / withdrawn. score_before/score_after بتتسجل لو القبول غيّر درجة سؤال.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (!Schema::hasColumn('exams', 'appeals_enabled')) {
                $table->boolean('appeals_enabled')->default(true);
            }
            if (!Schema::hasColumn('exams', 'appeal_window_days')) {
                $table->unsignedSmallInteger('appeal_window_days')->default(7);
            }
        });

        if (Schema::hasTable('exam_grade_appeals')) {
            return;
        }
        Schema::create('exam_grade_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('exam_attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->unsignedBigInteger('exam_question_id')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->text('response')->nullable();
            $table->unsignedBigInteger('resolved_by_academic_staff_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->decimal('score_before', 6, 2)->nullable();
            $table->decimal('score_after', 6, 2)->nullable();
            $table->timestamps();

            $table->index(['exam_id', 'status']);
            $table->index(['exam_attempt_id', 'status']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_grade_appeals');
        Schema::table('exams', function (Blueprint $table) {
            foreach (['appeals_enabled', 'appeal_window_days'] as $c) {
                if (Schema::hasColumn('exams', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
