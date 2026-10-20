<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam & Assessment System — Round 4 (Grading Core). جدولين جداد +
 * عمود واحد على exams الموجودة.
 *
 * exam_grades: صف واحد لكل (محاولة, سؤال) — الـ "وحدة" اللي التصحيح
 * بيتم عليها. الصف ده بيتعمل (placeholder، marks_awarded = null) لكل
 * سؤال في الامتحان لحظة ما المحاولة تتسلم (submitted/auto_submitted) —
 * راجع ExamGradingService::finalizeSubmission(). للأسئلة الموضوعية
 * (mcq/multi_select/true_false) بيتملى فورًا (source=automatic). للباقي
 * (short_answer/essay/file_upload) بيفضل marks_awarded=null لحد ما
 * المدرس (Round 4) أو الـ AI (Round 6) يصححها.
 *
 * max_marks بيتاخد snapshot وقت التصحيح (مش live من questions.marks) —
 * عشان لو المدرس عدّل درجة سؤال في بنك الأسئلة بعد كده، الدرجات القديمة
 * لمحاولات سابقة متتغيرش من تحتها.
 *
 * source enum فيه كل الخمس قيم من Phase 22 في السبك (automatic, instructor,
 * administrator, ai, regrade) من الأول — ai/regrade مش مستخدمة فعليًا
 * لحد Round 6، بس مفيش داعي لـ migration تانية بس عشان enum value زي ما
 * حصل مع exam_attempts.status في Round 3.
 *
 * exam_grade_history: log append-only بس — كل تغيير في marks_awarded على
 * صف exam_grades بيسجل سطر هنا (previous/new/changed_by/source/reason).
 * عمدًا من غير updated_at (الصف نفسه عمره ما بيتعدل بعد ما يتخلق).
 *
 * exams.results_published_at: لازم لـ result_visibility='manual' (Round 1
 * عرّف الـ enum بس من غير آلية "نشر" فعلية — دي هي). null يعني لسه ما
 * اتنشرش. immediate/after_close ميستخدموهاش خالص (الظهور بيتحدد من
 * attempt.status/exam.end_at مباشرة — راجع ExamGradingService::isResultVisibleTo()).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->foreignId('exam_question_id')->constrained('exam_questions')->cascadeOnDelete();
            $table->foreignId('exam_answer_id')->nullable()->constrained('exam_answers')->nullOnDelete();

            $table->decimal('marks_awarded', 6, 2)->nullable();
            $table->decimal('max_marks', 6, 2);
            $table->boolean('is_correct')->nullable();
            $table->text('feedback')->nullable();

            $table->enum('source', ['automatic', 'instructor', 'administrator', 'ai', 'regrade'])->nullable();
            $table->foreignId('graded_by_academic_staff_id')->nullable()->constrained('academic_staff')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();

            $table->timestamps();

            $table->unique(['exam_attempt_id', 'exam_question_id']);
        });

        Schema::create('exam_grade_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_grade_id')->constrained('exam_grades')->cascadeOnDelete();

            $table->decimal('previous_score', 6, 2)->nullable();
            $table->decimal('new_score', 6, 2);
            $table->enum('source', ['automatic', 'instructor', 'administrator', 'ai', 'regrade']);
            $table->foreignId('changed_by_academic_staff_id')->nullable()->constrained('academic_staff')->nullOnDelete();
            $table->text('reason')->nullable();

            $table->timestamp('changed_at');

            $table->index(['exam_grade_id', 'changed_at']);
        });

        Schema::table('exams', function (Blueprint $table) {
            if (!Schema::hasColumn('exams', 'results_published_at')) {
                $table->timestamp('results_published_at')->nullable()->after('result_visibility');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('results_published_at');
        });
        Schema::dropIfExists('exam_grade_history');
        Schema::dropIfExists('exam_grades');
    }
};
