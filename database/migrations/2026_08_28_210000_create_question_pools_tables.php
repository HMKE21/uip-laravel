<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam & Assessment System — Round 7 (Phases 6-7: Question Pools +
 * Randomization). أربع جداول جداد + عمودين على exam_questions.
 *
 * التصميم الأساسي (عشان نتجنب أي تغيير كاسر على exam_answers/exam_grades
 * اللي اتبنيت في Rounds 3-6، الاتنين مربوطين بـ exam_questions.id مباشرة):
 * exam_questions تفضل هي "كتالوج" كل سؤال ظهر (أو ممكن يظهر) في الامتحان
 * ده — سواء متضاف يدويًا (source=manual، زي Round 1 بالظبط) أو طلع من
 * pool لأي طالب (source=pool). exam_attempt_questions (الجدول الجديد
 * الأهم هنا) هو اللي بيحدد فعليًا "الأسئلة دي، بالترتيب ده، ظهرت لمحاولة
 * الطالب ده بالذات" — فمفيش داعي نلمس exam_answers/exam_grades خالص،
 * لسه بيتكلموا مع exam_questions.id زي ما هو من Round 1.
 *
 * question_pools (Phase 6): "بنك أسئلة كبير" منفصل عن question_bank —
 * pool واحد بينتمي لـ question_bank واحد (question_bank_id)، وبيحوي
 * مجموعة فرعية من أسئلة البنك ده (question_pool_questions، pure
 * membership pivot). الـ pool نفسه معاد استخدامه لأكتر من امتحان لو
 * حابب (زي question_bank بالظبط).
 *
 * exam_question_pools (Phase 6/7): إعداد pool واحد على امتحان واحد —
 * "اختار 20 سؤال عشوائي من الـ pool ده، بـ X درجة للسؤال". distribution
 * columns (JSON، nullable) اختياريين — لو موجودين لازم مجموعهم يساوي
 * questions_to_select بالظبط (الفحص في الـ service مش هنا). القرار
 * المتعمّد (راجع QuestionSelectionService::draw()): لو الاتنين
 * (difficulty_distribution وtopic_distribution) اتحطوا مع بعض، الأولوية
 * لـ difficulty_distribution بس — "Do not overcomplicate the UI unless
 * necessary" في السبك (Phase 6) بيدعم القرار ده بدل ما نحاول نحقق قيدين
 * متعارضين مع بعض في نفس السحب.
 *
 * exam_attempt_questions (Phase 7): صف واحد لكل (محاولة، exam_question)
 * فعليًا ظهر للطالب ده — sort_order هنا هو الترتيب الفعلي اللي الطالب
 * شافه (بعد أي خلط لو exams.randomize_questions=true)، منفصل تمامًا عن
 * exam_questions.sort_order (اللي فضل "ترتيب التأليف" الأصلي، Round 1).
 * بيتملى مرة واحدة بس وقت startAttempt() (QuestionSelectionService)،
 * وبيفضل ثابت طول عمر المحاولة (resume/reload مبيعيدش الخلط).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('question_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_bank_id')->constrained('question_banks')->cascadeOnDelete();
            $table->foreignId('created_by_academic_staff_id')->constrained('academic_staff')->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['question_bank_id']);
        });

        Schema::create('question_pool_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_pool_id')->constrained('question_pools')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['question_pool_id', 'question_id']);
        });

        Schema::table('exam_questions', function (Blueprint $table) {
            $table->enum('source', ['manual', 'pool'])->default('manual')->after('sort_order');
            $table->foreignId('question_pool_id')->nullable()->after('source')
                ->constrained('question_pools')->nullOnDelete();
        });

        Schema::create('exam_question_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('question_pool_id')->constrained('question_pools')->cascadeOnDelete();
            $table->unsignedInteger('questions_to_select');
            $table->decimal('marks_per_question', 6, 2);
            $table->json('difficulty_distribution')->nullable();
            $table->json('topic_distribution')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['exam_id', 'question_pool_id']);
        });

        Schema::create('exam_attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->foreignId('exam_question_id')->constrained('exam_questions')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['exam_attempt_id', 'exam_question_id']);
            $table->index(['exam_attempt_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempt_questions');
        Schema::dropIfExists('exam_question_pools');

        Schema::table('exam_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('question_pool_id');
            $table->dropColumn('source');
        });

        Schema::dropIfExists('question_pool_questions');
        Schema::dropIfExists('question_pools');
    }
};
