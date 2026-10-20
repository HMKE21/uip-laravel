<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam & Assessment System — Round 6 (AI Grading, Phases 17-21). عمود
 * واحد على questions + جدولين جداد.
 *
 * questions.keywords: عمود ناقص من الأعمدة اللي Round 1 حطها مسبقًا
 * (model_answer/expected_concepts/grading_instructions/ai_grading_enabled/
 * accepted_answers/case_sensitive كلها موجودة من migration 150000 — راجع
 * docblockها). الفرق بين keywords وexpected_concepts (Phase 20): keywords
 * كلمات مفتاحية قصيرة المفروض تظهر حرفيًا، expected_concepts أفكار/مفاهيم
 * أعم ممكن الطالب يعبّر عنها بصياغات مختلفة — الاتنين بيتبعتوا للـ AI في
 * برومبت واحد (راجع AiExamGradingService::buildUserPrompt()) بس منفصلين
 * عشان المدرس يقدر يدير كل واحد لوحده في الفرونت.
 *
 * exam_rubrics / exam_rubric_criteria (Phase 19): rubric واحد بالظبط لكل
 * سؤال (unique على question_id) — لو المدرس محتاج نسخة تانية بيعدّل نفس
 * الـ criteria مباشرة (مفيش versioning هنا عمدًا، برّه نطاق الجولة دي؛
 * تاريخ تغيير الدرجة الفعلي محفوظ أصلاً في exam_grade_history من Round 4).
 * exam_rubric_criteria.max_points مجموعها لازم يساوي questions.marks —
 * الفحص ده في ExamSystemService::saveRubric()، مش هنا (migration مالهاش
 * دخل بقواعد أعمال بين جدولين).
 *
 * exam_ai_gradings (Phase 18): صف تشخيصي واحد لكل exam_grades (unique على
 * exam_grade_id — 1-1، بيتكتب فوقه بعض عند regrade، نفس فلسفة exam_grades
 * نفسها اللي بتتحدّث in-place). status بيتبع دورة حياة الـ job (queued
 * لحظة finalizeSubmission()/regradeQuestionWithAi()، processing لحظة
 * الـ job يبدأ، completed/failed لما يخلص — راجع
 * ExamGradingService::runAiGrading()). marks_awarded/confidence/feedback/
 * strengths/missing_concepts/reasoning_summary هي بالظبط شكل الـ JSON من
 * السبك (Phase 18) — reasoning_summary مقصود يكون ملخص قصير مش
 * chain-of-thought كامل (الـ AI system prompt بيوضح ده صراحة، راجع
 * AiExamGradingService). لو فشل الاتصال بالـ AI، error_message بيتسجل
 * وexam_grades.marks_awarded بيفضل null (pending) — مفيش أي درجة وهمية
 * بتتحط أبدًا لو الـ AI فشل فعليًا.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            if (!Schema::hasColumn('questions', 'keywords')) {
                $table->json('keywords')->nullable()->after('expected_concepts');
            }
        });

        Schema::create('exam_rubrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->foreignId('created_by_academic_staff_id')->constrained('academic_staff')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('question_id');
        });

        Schema::create('exam_rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_rubric_id')->constrained('exam_rubrics')->cascadeOnDelete();
            $table->string('label', 200);
            $table->decimal('max_points', 6, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['exam_rubric_id', 'sort_order']);
        });

        Schema::create('exam_ai_gradings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_grade_id')->constrained('exam_grades')->cascadeOnDelete();

            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');
            $table->decimal('marks_awarded', 6, 2)->nullable();
            $table->decimal('max_marks', 6, 2)->nullable();
            $table->decimal('confidence', 4, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->json('strengths')->nullable();
            $table->json('missing_concepts')->nullable();
            $table->text('reasoning_summary')->nullable();
            $table->string('model', 100)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique('exam_grade_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_ai_gradings');
        Schema::dropIfExists('exam_rubric_criteria');
        Schema::dropIfExists('exam_rubrics');

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('keywords');
        });
    }
};
