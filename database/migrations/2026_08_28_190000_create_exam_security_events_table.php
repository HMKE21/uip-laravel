<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam & Assessment System — Round 5 (Secure Exam Mode + Security Events,
 * Phases 13-16). جدول واحد جديد + عمودين على exams الموجودة.
 *
 * exam_security_events: append-only log — نفس فلسفة exam_grade_history
 * (من غير updated_at، الصف عمره ما بيتعدل بعد ما يتخلق). exam_id و
 * student_id متكررين هنا (denormalized من exam_attempt_id) — نفس قرار
 * تكرار university_id في question_banks (Round 1 docblock): بيسمح
 * للمدرس يفلتر/يعد أحداث امتحان كامل من غير join على exam_attempts في
 * كل query (راجع ExamSecurityService::listForExam لاحقًا لو احتجناها).
 *
 * event_type بيحتوي كل الـ 14 قيمة من Phase 16 بالظبط من الأول (نفس قرار
 * exam_grades.source في Round 4 migration) — بعضها system-only (مش بتتقبل
 * من العميل، راجع ExamSecurityService::CLIENT_EVENTS) وبعضها
 * client-reportable (من متصفح الطالب وقت المحاولة).
 *
 * occurred_at = now() بتاعة السيرفر دايمًا، مفيش أي وقت من العميل بيتصدق —
 * نفس مبدأ enforceTimer() بالظبط ("Do NOT rely only on JavaScript" بتنطبق
 * على توقيت أحداث الأمان زي ما بتنطبق على التايمر نفسه).
 *
 * is_violation بيتحدد وقت التسجيل (مش عمود محسوب) حسب VIOLATION_EVENTS في
 * الخدمة — بيفرق بين حدث إعلامي بحت (exam_started, fullscreen_entered,
 * window_focus, question_changed, answer_saved) وحدث بيزوّد
 * exam_attempts.violations_count فعليًا (fullscreen_exited, tab_switch,
 * window_blur, copy/paste/cut_attempt). مخزّن صراحة عشان أي تعديل مستقبلي
 * لتصنيف حدث معين ميغيّرش تفسير الصفوف التاريخية القديمة من تحتها.
 *
 * exams.secure_mode_enabled/max_violations: إعدادات الامتحان اللي بتتحدد
 * وقت الإنشاء (ExamSystemService::createExam) — max_violations=null يعني
 * "مفيش حد أقصى" (مفيش auto-submit بسبب المخالفات، بس التسجيل نفسه
 * فاضل شغال دايمًا طول ما secure_mode_enabled=true).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            $table->enum('event_type', [
                'exam_started', 'exam_submitted', 'auto_submitted',
                'fullscreen_entered', 'fullscreen_exited',
                'tab_switch', 'window_blur', 'window_focus',
                'copy_attempt', 'paste_attempt', 'cut_attempt',
                'question_changed', 'answer_saved', 'time_expired',
            ]);
            $table->boolean('is_violation')->default(false);
            $table->json('metadata')->nullable();

            $table->timestamp('occurred_at');

            $table->index(['exam_attempt_id', 'occurred_at']);
            $table->index(['exam_id', 'event_type']);
        });

        Schema::table('exams', function (Blueprint $table) {
            if (!Schema::hasColumn('exams', 'secure_mode_enabled')) {
                $table->boolean('secure_mode_enabled')->default(true)->after('result_visibility');
            }
            if (!Schema::hasColumn('exams', 'max_violations')) {
                $table->unsignedTinyInteger('max_violations')->nullable()->default(3)->after('secure_mode_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn(['secure_mode_enabled', 'max_violations']);
        });
        Schema::dropIfExists('exam_security_events');
    }
};
