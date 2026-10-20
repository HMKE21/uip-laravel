
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam & Assessment System — Round 3
 *
 * Attempts + Timer + Auto-save.
 *
 * Tables:
 *
 * 1. exam_attempts
 *    محاولة طالب واحدة على امتحان واحد.
 *
 * 2. exam_answers
 *    إجابة طالب على سؤال واحد داخل محاولة واحدة.
 *
 * exam_attempts.status لا يحتوي على not_started.
 * الـ attempt يتم إنشاؤها فقط عندما يبدأ الطالب الامتحان فعليًا.
 *
 * statuses:
 * - in_progress
 * - submitted
 * - auto_submitted
 * - expired
 * - cancelled
 * - grading
 * - graded
 *
 * expires_at يتم حسابه وقت Start Exam بناءً على:
 *
 * started_at + exam.duration_minutes
 *
 * لذلك لا يتم إعطاؤه useCurrent().
 *
 * تم جعل expires_at nullable لتجنب مشكلة MySQL/MariaDB:
 *
 * SQLSTATE[42000]: Invalid default value for 'expires_at'
 *
 * ويتم تعيين قيمته فعليًا في Service Layer عند بدء الامتحان.
 *
 * exam_answers مرتبطة بـ exam_question_id وليس question_id
 * لأن نفس السؤال يمكن استخدامه في أكثر من امتحان.
 */
return new class extends Migration
{
  /**
   * Run the migrations.
   */
  public function up(): void
  {
    /*
         * ============================================================
         * EXAM ATTEMPTS
         * ============================================================
         */
    Schema::create('exam_attempts', function (Blueprint $table) {
      $table->id();

      /*
             * الامتحان.
             */
      $table->foreignId('exam_id')
        ->constrained('exams')
        ->cascadeOnDelete();

      /*
             * الطالب.
             */
      $table->foreignId('student_id')
        ->constrained('students')
        ->cascadeOnDelete();

      /*
             * رقم المحاولة.
             *
             * مثال:
             * 1 = المحاولة الأولى
             * 2 = المحاولة الثانية
             * 3 = المحاولة الثالثة
             */
      $table->unsignedTinyInteger('attempt_number')
        ->default(1);

      /*
             * حالة المحاولة.
             */
      $table->enum('status', [
        'in_progress',
        'submitted',
        'auto_submitted',
        'expired',
        'cancelled',
        'grading',
        'graded',
      ])->default('in_progress');

      /*
             * وقت بدء الامتحان.
             *
             * يبدأ تلقائيًا عند إنشاء الـ attempt.
             */
      $table->timestamp('started_at')
        ->useCurrent();

      /*
             * آخر نشاط للطالب.
             *
             * يبدأ بوقت إنشاء الـ attempt،
             * ثم يتم تحديثه من Service Layer أثناء الامتحان.
             */
      $table->timestamp('last_activity_at')
        ->useCurrent();

      /*
             * وقت انتهاء الامتحان.
             *
             * لا نستخدم useCurrent() هنا.
             *
             * يتم حسابه عند Start Exam:
             *
             * started_at + duration_minutes
             *
             * وتم جعله nullable بسبب توافق MySQL/MariaDB.
             */
      $table->timestamp('expires_at')
        ->nullable();

      /*
             * وقت تسليم الامتحان.
             */
      $table->timestamp('submitted_at')
        ->nullable();

      /*
             * هل تم التسليم تلقائيًا بسبب انتهاء الوقت؟
             */
      $table->boolean('auto_submitted')
        ->default(false);

      /*
             * عدد مخالفات الطالب.
             */
      $table->unsignedInteger('violations_count')
        ->default(0);

      /*
             * الدرجة النهائية.
             *
             * NULL حتى يتم التصحيح.
             */
      $table->decimal('score', 8, 2)
        ->nullable();

      /*
             * النسبة المئوية.
             *
             * NULL حتى يتم التصحيح.
             */
      $table->decimal('percentage', 5, 2)
        ->nullable();

      /*
             * created_at / updated_at
             */
      $table->timestamps();

      /*
             * منع تكرار نفس رقم المحاولة
             * لنفس الطالب على نفس الامتحان.
             */
      $table->unique([
        'exam_id',
        'student_id',
        'attempt_number',
      ]);

      /*
             * Index للبحث عن محاولات الطالب حسب الحالة.
             */
      $table->index([
        'student_id',
        'status',
      ]);

      /*
             * Index مهم للـ Auto Submit Sweep.
             *
             * يسمح بالبحث عن المحاولات التي:
             *
             * status = in_progress
             * و expires_at <= now()
             */
      $table->index([
        'status',
        'expires_at',
      ]);
    });

    /*
         * ============================================================
         * EXAM ANSWERS
         * ============================================================
         */
    Schema::create('exam_answers', function (Blueprint $table) {
      $table->id();

      /*
             * محاولة الامتحان.
             */
      $table->foreignId('exam_attempt_id')
        ->constrained('exam_attempts')
        ->cascadeOnDelete();

      /*
             * السؤال داخل الامتحان.
             *
             * نستخدم exam_question_id وليس question_id.
             */
      $table->foreignId('exam_question_id')
        ->constrained('exam_questions')
        ->cascadeOnDelete();

      /*
             * الاختيارات المختارة في MCQ.
             *
             * JSON لأن السؤال قد يسمح باختيار واحد
             * أو أكثر من اختيار.
             */
      $table->json('selected_option_ids')
        ->nullable();

      /*
             * الإجابة النصية.
             *
             * تستخدم مع:
             * - true_false
             * - short_answer
             * - essay
             */
      $table->text('answer_text')
        ->nullable();

      /*
             * وقت تسجيل الإجابة أو تحديثها.
             *
             * useCurrent() لتجنب مشاكل TIMESTAMP NOT NULL
             * بدون default في MySQL/MariaDB.
             */
      $table->timestamp('answered_at')
        ->useCurrent();

      /*
             * created_at / updated_at
             */
      $table->timestamps();

      /*
             * الطالب لا يمكن أن يمتلك أكثر من إجابة
             * لنفس السؤال داخل نفس المحاولة.
             *
             * وهذا يسمح باستخدام upsert في Auto-save.
             */
      $table->unique([
        'exam_attempt_id',
        'exam_question_id',
      ]);
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    /*
         * حذف الإجابات أولًا لأنها تعتمد على exam_attempts.
         */
    Schema::dropIfExists('exam_answers');

    /*
         * ثم حذف محاولات الامتحان.
         */
    Schema::dropIfExists('exam_attempts');
  }
};
