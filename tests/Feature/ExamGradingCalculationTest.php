<?php

namespace Tests\Feature;

use App\Services\ExamGradingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests لمحرك حساب الدرجات في ExamGradingService:
 *
 *  - evaluateObjectiveAnswer() (عبر finalizeSubmission()/autoGradeAttempt()):
 *    mcq/multi_select كله-أو-لا-حاجة (مفيش partial credit)، true_false
 *    تطابق نصي، إجابة فاضية = غلط دايمًا.
 *  - "Never clobber a manual grade": finalizeSubmission()/autoGradeAttempt()
 *    ما يلمسوش صف اتصحح instructor قبل كده.
 *  - recomputeAttemptTotals(): المحاولة تفضل status=grading (score/percentage
 *    = null) لحد ما كل الأسئلة تتصحح، وبعدين score = مجموع الدرجات
 *    وpercentage = round(score/total_marks * 100, 2)، وstatus يبقى graded.
 *  - gradeManually(): بيرفض درجة برّه [0, max_marks]، وبيرفض تصحيح
 *    محاولة لسه in_progress.
 *
 * نفس ملاحظة البيئة اللي في ExamTargetEligibilityTest: DatabaseTransactions
 * بدل RefreshDatabase لنفس السبب (migrations الجداول الأساسية مش جزء من
 * هذا الباتش).
 */
class ExamGradingCalculationTest extends TestCase
{
    use DatabaseTransactions;

    private ExamGradingService $service;
    private int $universityId = 1;
    private int $staffId;
    private int $academicStaffUserId;
    private int $questionBankId;
    private int $examId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ExamGradingService::class);

        DB::table('universities')->insert(['id' => $this->universityId, 'name' => 'Test University']);

        $this->academicStaffUserId = DB::table('users')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'full_name' => 'Dr. Staff',
            'email' => 'staff_' . uniqid() . '@test.local', 'password_hash' => bcrypt('secret'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->staffId = DB::table('academic_staff')->insertGetId([
            'user_id' => $this->academicStaffUserId, 'university_id' => $this->universityId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->questionBankId = DB::table('question_banks')->insertGetId([
            'university_id' => $this->universityId, 'created_by_academic_staff_id' => $this->staffId,
            'title' => 'Bank', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->examId = DB::table('exams')->insertGetId([
            'university_id' => $this->universityId, 'created_by_academic_staff_id' => $this->staffId,
            'title' => 'Sample Exam', 'duration_minutes' => 60, 'status' => 'published',
            'total_marks' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeStaff(): object
    {
        return (object) ['id' => $this->staffId, 'user_id' => $this->academicStaffUserId];
    }

    private function makeStudent(): int
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'full_name' => 'Student ' . uniqid(),
            'email' => 'student_' . uniqid() . '@test.local', 'password_hash' => bcrypt('secret'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('students')->insertGetId([
            'user_id' => $userId, 'university_id' => $this->universityId,
            'student_number' => 'S' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeMcqQuestion(float $marks, array $optionIsCorrect): array
    {
        $questionId = DB::table('questions')->insertGetId([
            'question_bank_id' => $this->questionBankId, 'type' => 'mcq', 'prompt' => 'Q?',
            'marks' => $marks, 'created_by_academic_staff_id' => $this->staffId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $optionIds = [];
        foreach ($optionIsCorrect as $i => $isCorrect) {
            $optionIds[] = DB::table('question_options')->insertGetId([
                'question_id' => $questionId, 'option_text' => "Option $i",
                'is_correct' => $isCorrect, 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return [$questionId, $optionIds];
    }

    private function makeTrueFalseQuestion(float $marks, string $correctAnswer): int
    {
        return DB::table('questions')->insertGetId([
            'question_bank_id' => $this->questionBankId, 'type' => 'true_false', 'prompt' => 'T/F?',
            'marks' => $marks, 'correct_answer' => $correctAnswer,
            'created_by_academic_staff_id' => $this->staffId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeEssayQuestion(float $marks, bool $aiGradingEnabled = false): int
    {
        return DB::table('questions')->insertGetId([
            'question_bank_id' => $this->questionBankId, 'type' => 'essay', 'prompt' => 'Explain X.',
            'marks' => $marks, 'ai_grading_enabled' => $aiGradingEnabled,
            'created_by_academic_staff_id' => $this->staffId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function attachToExam(int $questionId): int
    {
        return DB::table('exam_questions')->insertGetId([
            'exam_id' => $this->examId, 'question_id' => $questionId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function startAttempt(int $studentId, array $examQuestionIds): int
    {
        $attemptId = DB::table('exam_attempts')->insertGetId([
            'exam_id' => $this->examId, 'student_id' => $studentId, 'attempt_number' => 1,
            'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($examQuestionIds as $i => $eqId) {
            DB::table('exam_attempt_questions')->insert([
                'exam_attempt_id' => $attemptId, 'exam_question_id' => $eqId, 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $attemptId;
    }

    private function answer(int $attemptId, int $examQuestionId, array $selectedOptionIds = [], ?string $text = null): void
    {
        DB::table('exam_answers')->insert([
            'exam_attempt_id' => $attemptId, 'exam_question_id' => $examQuestionId,
            'selected_option_ids' => $selectedOptionIds ? json_encode($selectedOptionIds) : null,
            'answer_text' => $text, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function markSubmitted(int $attemptId): \App\Models\ExamAttempt
    {
        $attempt = \App\Models\ExamAttempt::findOrFail($attemptId);
        $attempt->status = 'submitted';
        $attempt->submitted_at = now();
        $attempt->save();
        return $attempt;
    }

    #[Test]
    public function a_correct_mcq_answer_is_awarded_full_marks(): void
    {
        [$qId, $optIds] = $this->makeMcqQuestion(5, [true, false, false]);
        $eqId = $this->attachToExam($qId);
        DB::table('exams')->where('id', $this->examId)->update(['total_marks' => 5]);

        $studentId = $this->makeStudent();
        $attemptId = $this->startAttempt($studentId, [$eqId]);
        $this->answer($attemptId, $eqId, [$optIds[0]]);
        $attempt = $this->markSubmitted($attemptId);

        $this->service->finalizeSubmission($attempt);

        $grade = DB::table('exam_grades')->where('exam_attempt_id', $attemptId)->first();
        $this->assertEquals(5.0, (float) $grade->marks_awarded);
        $this->assertEquals(1, (int) $grade->is_correct);
    }

    #[Test]
    public function an_incorrect_mcq_answer_gets_zero_not_partial_credit(): void
    {
        // multi_select/mcq من Round 4: all-or-nothing. اختيار جزء بس من
        // مجموعة الاختيارات الصح المفروض يدّي صفر، مش درجة جزئية.
        [$qId, $optIds] = $this->makeMcqQuestion(10, [true, true, false]);
        $eqId = $this->attachToExam($qId);

        $studentId = $this->makeStudent();
        $attemptId = $this->startAttempt($studentId, [$eqId]);
        $this->answer($attemptId, $eqId, [$optIds[0]]); // واحد بس من الاتنين الصح
        $attempt = $this->markSubmitted($attemptId);

        $this->service->finalizeSubmission($attempt);

        $grade = DB::table('exam_grades')->where('exam_attempt_id', $attemptId)->first();
        $this->assertEquals(0.0, (float) $grade->marks_awarded);
        $this->assertEquals(0, (int) $grade->is_correct);
    }

    #[Test]
    public function a_blank_answer_is_always_graded_as_incorrect(): void
    {
        $qId = $this->makeTrueFalseQuestion(4, 'true');
        $eqId = $this->attachToExam($qId);

        $studentId = $this->makeStudent();
        $attemptId = $this->startAttempt($studentId, [$eqId]);
        // مفيش صف exam_answers خالص — الطالب سابها فاضية.
        $attempt = $this->markSubmitted($attemptId);

        $this->service->finalizeSubmission($attempt);

        $grade = DB::table('exam_grades')->where('exam_attempt_id', $attemptId)->first();
        $this->assertEquals(0.0, (float) $grade->marks_awarded);
        $this->assertEquals(0, (int) $grade->is_correct);
    }

    #[Test]
    public function subjective_questions_stay_pending_until_graded_and_block_the_attempt_total(): void
    {
        [$mcqId, $optIds] = $this->makeMcqQuestion(6, [true, false]);
        $mcqEqId = $this->attachToExam($mcqId);
        $essayId = $this->makeEssayQuestion(4); // ai_grading_enabled=false → يفضل pending
        $essayEqId = $this->attachToExam($essayId);
        DB::table('exams')->where('id', $this->examId)->update(['total_marks' => 10]);

        $studentId = $this->makeStudent();
        $attemptId = $this->startAttempt($studentId, [$mcqEqId, $essayEqId]);
        $this->answer($attemptId, $mcqEqId, [$optIds[0]]);
        $this->answer($attemptId, $essayEqId, [], 'My essay answer.');
        $attempt = $this->markSubmitted($attemptId);

        $this->service->finalizeSubmission($attempt);

        $essayGrade = DB::table('exam_grades')->where('exam_attempt_id', $attemptId)
            ->where('exam_question_id', $essayEqId)->first();
        $this->assertNull($essayGrade->marks_awarded, 'Essay question should stay pending for the instructor.');

        // لسه مش كل الأسئلة اتصححت → المحاولة تفضل "grading"، مش score نهائي.
        $attempt->refresh();
        $this->assertSame('grading', $attempt->status);
        $this->assertNull($attempt->score);
        $this->assertNull($attempt->percentage);

        // دلوقتي المدرس يصحح المقالي يدويًا → المفروض المحاولة تتقفل وتتحول graded.
        $this->service->gradeManually($attempt, $essayEqId, 3.0, 'Good effort.', $this->makeStaff());

        $attempt->refresh();
        $this->assertSame('graded', $attempt->status);
        $this->assertEquals(6.0 + 3.0, (float) $attempt->score);
        $this->assertEquals(90.0, (float) $attempt->percentage); // 9/10 * 100
    }

    #[Test]
    public function percentage_is_rounded_to_two_decimals_against_exam_total_marks(): void
    {
        [$qId, $optIds] = $this->makeMcqQuestion(1, [true, false, false]);
        $eqId = $this->attachToExam($qId);
        // total_marks=3 عمدًا (مش نفس درجة السؤال) عشان نتأكد إن الحساب
        // بيستخدم exam.total_marks مش مجموع درجات المحاولة نفسها.
        DB::table('exams')->where('id', $this->examId)->update(['total_marks' => 3]);

        $studentId = $this->makeStudent();
        $attemptId = $this->startAttempt($studentId, [$eqId]);
        $this->answer($attemptId, $eqId, [$optIds[0]]);
        $attempt = $this->markSubmitted($attemptId);

        $this->service->finalizeSubmission($attempt);
        $attempt->refresh();

        // 1/3 * 100 = 33.333... → لازم يترصّ لـ 33.33 بالظبط، مش يتقطع أو يتقرب غلط.
        $this->assertEquals(33.33, (float) $attempt->percentage);
    }

    #[Test]
    public function automatic_regrading_never_overwrites_an_instructor_grade(): void
    {
        [$qId, $optIds] = $this->makeMcqQuestion(5, [true, false]);
        $eqId = $this->attachToExam($qId);

        $studentId = $this->makeStudent();
        $attemptId = $this->startAttempt($studentId, [$eqId]);
        $this->answer($attemptId, $eqId, [$optIds[1]]); // إجابة غلط في الأول
        $attempt = $this->markSubmitted($attemptId);

        $this->service->finalizeSubmission($attempt); // auto-grade: 0

        // المدرس يتدخل يدويًا ويدّي درجة كاملة (override).
        $this->service->gradeManually($attempt, $eqId, 5.0, 'Accepted alternate reasoning.', $this->makeStaff());

        // أي إعادة تصحيح أوتوماتيك بعد كده (auto-grade sweep) لازم تسيب
        // درجة المدرس زي ما هي، مش ترجعها 0 تاني.
        $this->service->autoGradeAttempt($attempt);

        $grade = DB::table('exam_grades')->where('exam_attempt_id', $attemptId)->first();
        $this->assertEquals(5.0, (float) $grade->marks_awarded);
        $this->assertSame('instructor', $grade->source);
    }

    #[Test]
    public function manual_grading_rejects_marks_outside_the_valid_range(): void
    {
        $qId = $this->makeEssayQuestion(10);
        $eqId = $this->attachToExam($qId);

        $studentId = $this->makeStudent();
        $attemptId = $this->startAttempt($studentId, [$eqId]);
        $this->answer($attemptId, $eqId, [], 'Some answer.');
        $attempt = $this->markSubmitted($attemptId);
        $this->service->finalizeSubmission($attempt);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->gradeManually($attempt, $eqId, 15.0, null, $this->makeStaff()); // أكبر من max_marks=10
    }

    #[Test]
    public function manual_grading_rejects_attempts_that_have_not_been_submitted_yet(): void
    {
        $qId = $this->makeEssayQuestion(10);
        $eqId = $this->attachToExam($qId);

        $studentId = $this->makeStudent();
        $attemptId = $this->startAttempt($studentId, [$eqId]); // status لسه in_progress
        $attempt = \App\Models\ExamAttempt::findOrFail($attemptId);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->gradeManually($attempt, $eqId, 5.0, null, $this->makeStaff());
    }
}
