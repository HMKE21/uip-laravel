<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند "Exam & Assessment System" — Round 1 (Foundation).
 *
 * أول 5 جداول بس من الموديول الكامل: question_banks, questions,
 * question_options, exams, exam_questions (pivot). النطاق ده عمدًا بيوقف
 * قبل الـ targeting/attempts/grading/security-events — كل واحدة منهم
 * هتيجي في round لاحق مستقل، زي ما اتفقنا في خطة البناء.
 *
 * نفس قرارات group_hub migration (2026_08_28_000100) بالظبط: id()
 * bigIncrements عادي، foreignId()->constrained() على الجداول القديمة
 * (universities/faculties/departments/programs/academic_staff/users)
 * اللي أعمدتها id بيجي bigIncrements برضه، فمفيش تعارض نوع.
 *
 * ownership: كل exam/question_bank مربوط بـ created_by_academic_staff_id
 * (academic_staff.id، مش users.id مباشرة) — نفس القرار اللي
 * StaffAssignment/AcademicStaff نفسها بتمشي عليه؛ الخدمة هي اللي بتشتق
 * الـ academic_staff_id ده من uip_user_id (الطبقة اللي التوكن بيديها)،
 * مش بتاخده من العميل. university_id/faculty_id/department_id متكررين
 * هنا (denormalized) بدل ما يتحسبوا كل مرة عبر join لـ academic_staff —
 * نفس سبب تكرارهم في students/academic_staff نفسها، وبيسهّل فلترة
 * RBAC (بند 26 لاحقًا: كلية/جامعة بس تشوف اللي في نطاقها) من غير join
 * إضافي على كل query.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained('universities')->cascadeOnDelete();
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('created_by_academic_staff_id')->constrained('academic_staff')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('subject', 150)->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['university_id', 'created_by_academic_staff_id']);
            $table->index(['department_id', 'status']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_bank_id')->constrained('question_banks')->cascadeOnDelete();
            $table->enum('type', ['mcq', 'multi_select', 'true_false', 'short_answer', 'essay', 'file_upload']);
            $table->text('prompt');
            $table->decimal('marks', 6, 2)->default(1);
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');
            $table->string('topic', 150)->nullable();
            $table->json('tags')->nullable();

            // short_answer grading
            $table->text('correct_answer')->nullable();
            $table->json('accepted_answers')->nullable();
            $table->boolean('case_sensitive')->default(false);

            // essay / short_answer AI + manual grading guidance
            $table->text('model_answer')->nullable();
            $table->json('expected_concepts')->nullable();
            $table->text('grading_instructions')->nullable();
            $table->boolean('ai_grading_enabled')->default(false);

            $table->text('explanation')->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->foreignId('created_by_academic_staff_id')->constrained('academic_staff')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['question_bank_id', 'status']);
            $table->index(['question_bank_id', 'type']);
            $table->index(['question_bank_id', 'difficulty']);
        });

        // MCQ / multi_select options. true_false is modeled as two implicit
        // options (see QuestionService) rather than stored rows, to avoid
        // every true_false question carrying two redundant option records —
        // matches the "don't overcomplicate" note in the spec for simple types.
        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->text('option_text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['question_id', 'sort_order']);
        });

        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained('universities')->cascadeOnDelete();
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('created_by_academic_staff_id')->constrained('academic_staff')->cascadeOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('subject', 150)->nullable();
            $table->string('academic_year', 20)->nullable();
            $table->string('semester', 20)->nullable();

            $table->unsignedInteger('duration_minutes');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->unsignedTinyInteger('max_attempts')->default(1);
            $table->decimal('passing_score', 6, 2)->nullable();
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->text('instructions')->nullable();

            $table->boolean('randomize_questions')->default(false);
            $table->boolean('randomize_options')->default(false);

            // Phase 24: 'immediate' (score+answers right after submit),
            // 'after_close' (only once exam end_at has passed),
            // 'manual' (instructor publishes results explicitly — round-4 grading work).
            $table->enum('result_visibility', ['immediate', 'after_close', 'manual'])->default('after_close');

            $table->enum('status', [
                'draft', 'scheduled', 'published', 'active', 'closed', 'grading', 'graded', 'archived',
            ])->default('draft');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['university_id', 'created_by_academic_staff_id']);
            $table->index(['department_id', 'status']);
            $table->index(['status', 'start_at', 'end_at']);
        });

        // exam <-> question, with optional per-exam marks override and explicit
        // ordering (randomize_questions shuffles at attempt-time in the service
        // layer — sort_order here is always the instructor's authored order).
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->decimal('marks_override', 6, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['exam_id', 'question_id']);
            $table->index(['exam_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('exams');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('question_banks');
    }
};
