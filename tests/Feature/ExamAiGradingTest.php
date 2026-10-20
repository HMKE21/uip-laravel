<?php

namespace Tests\Feature;

use App\Jobs\GradeEssayAnswerWithAiJob;
use App\Services\AiExamGradingService;
use App\Services\ExamGradingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests لخط أنابيب تصحيح الـ AI (ExamGradingService::
 * maybeDispatchAiGrading() + runAiGrading()). أي استدعاء حقيقي لـ
 * AiExamGradingService::grade() متبدّل بـ mock هنا — مفيش أي اتصال شبكة
 * فعلي بأي AI provider في الاختبارات دي.
 *
 *  - الـ job بيتبعت بس لو (essay/short_answer) + ai_grading_enabled=true +
 *    فيه إجابة نص فعلية.
 *  - نجاح الـ AI: الدرجة بتتسجل source='ai'، exam_ai_gradings يبقى
 *    completed بكل التفاصيل، وexam_grade_history بيسجل الحركة.
 *  - فشل الـ AI (استثناء من الـ client): مفيش درجة وهمية — marks_awarded
 *    يفضل زي ما هو (null)، exam_ai_gradings يبقى failed مع السبب.
 *  - "Never clobber a manual grade" بتنطبق على AI برضه: لو درجة السؤال
 *    already source='instructor'، runAiGrading() تطلع no-op تمامًا.
 *  - الـ rubric criteria (لو موجودة) بتتمرر فعليًا لـ AiExamGradingService.
 */
class ExamAiGradingTest extends TestCase
{
    use DatabaseTransactions;

    private ExamGradingService $service;
    private int $universityId = 1;
    private int $staffId;
    private int $questionBankId;
    private int $examId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ExamGradingService::class);

        DB::table('universities')->insert(['id' => $this->universityId, 'name' => 'Test University']);
        $staffUserId = DB::table('users')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'full_name' => 'Dr. Staff',
            'email' => 'staff_' . uniqid() . '@test.local', 'password_hash' => bcrypt('secret'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->staffId = DB::table('academic_staff')->insertGetId([
            'user_id' => $staffUserId, 'university_id' => $this->universityId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->questionBankId = DB::table('question_banks')->insertGetId([
            'university_id' => $this->universityId, 'created_by_academic_staff_id' => $this->staffId,
            'title' => 'Bank', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->examId = DB::table('exams')->insertGetId([
            'university_id' => $this->universityId, 'created_by_academic_staff_id' => $this->staffId,
            'title' => 'Exam', 'duration_minutes' => 60, 'status' => 'published',
            'total_marks' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
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

    private function makeEssayQuestion(bool $aiEnabled, float $marks = 10): int
    {
        return DB::table('questions')->insertGetId([
            'question_bank_id' => $this->questionBankId, 'type' => 'essay', 'prompt' => 'Explain X.',
            'marks' => $marks, 'ai_grading_enabled' => $aiEnabled,
            'created_by_academic_staff_id' => $this->staffId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** بيعمل محاولة submitted بسؤال مقالي واحد وإجابة نص، ويرجّع [attempt, examQuestionId, gradeId]. */
    private function submittedEssayAttempt(bool $aiEnabled, ?string $answerText = 'My essay answer covers the topic.'): array
    {
        $questionId = $this->makeEssayQuestion($aiEnabled);
        $examQuestionId = DB::table('exam_questions')->insertGetId([
            'exam_id' => $this->examId, 'question_id' => $questionId, 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $studentId = $this->makeStudent();
        $attemptId = DB::table('exam_attempts')->insertGetId([
            'exam_id' => $this->examId, 'student_id' => $studentId, 'attempt_number' => 1,
            'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('exam_attempt_questions')->insert([
            'exam_attempt_id' => $attemptId, 'exam_question_id' => $examQuestionId, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($answerText !== null) {
            DB::table('exam_answers')->insert([
                'exam_attempt_id' => $attemptId, 'exam_question_id' => $examQuestionId,
                'answer_text' => $answerText, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $attempt = \App\Models\ExamAttempt::findOrFail($attemptId);
        $attempt->status = 'submitted';
        $attempt->submitted_at = now();
        $attempt->save();

        $this->service->finalizeSubmission($attempt);

        $gradeId = DB::table('exam_grades')
            ->where('exam_attempt_id', $attemptId)->where('exam_question_id', $examQuestionId)
            ->value('id');

        return [$attempt->fresh(), $examQuestionId, $gradeId];
    }

    #[Test]
    public function submitting_an_ai_enabled_essay_with_an_answer_queues_the_grading_job(): void
    {
        Queue::fake();

        [, , $gradeId] = $this->submittedEssayAttempt(aiEnabled: true);

        Queue::assertPushed(GradeEssayAnswerWithAiJob::class);
        $aiGrading = DB::table('exam_ai_gradings')->where('exam_grade_id', $gradeId)->first();
        $this->assertSame('queued', $aiGrading->status);
    }

    #[Test]
    public function an_essay_with_ai_grading_disabled_never_queues_a_job(): void
    {
        Queue::fake();

        $this->submittedEssayAttempt(aiEnabled: false);

        Queue::assertNotPushed(GradeEssayAnswerWithAiJob::class);
    }

    #[Test]
    public function a_blank_answer_is_never_sent_to_ai_grading_even_if_enabled(): void
    {
        Queue::fake();

        $this->submittedEssayAttempt(aiEnabled: true, answerText: null);

        Queue::assertNotPushed(GradeEssayAnswerWithAiJob::class);
    }

    #[Test]
    public function a_successful_ai_grade_is_persisted_and_recomputes_the_attempt_total(): void
    {
        Queue::fake(); // نمنع أي محاولة تشغيل حقيقي، وننادي runAiGrading() يدويًا بعد كده

        [$attempt, , $gradeId] = $this->submittedEssayAttempt(aiEnabled: true);

        $fakeAi = Mockery::mock(AiExamGradingService::class);
        $fakeAi->shouldReceive('grade')->once()->andReturn([
            'marks_awarded'     => 7.5,
            'confidence'        => 0.82,
            'feedback'          => 'Good structure, missing one key point.',
            'strengths'         => ['Clear definition'],
            'missing_concepts'  => ['Edge case handling'],
            'reasoning_summary' => 'Covered core idea but missed an edge case.',
        ]);
        $this->app->instance(AiExamGradingService::class, $fakeAi);

        $service = app(ExamGradingService::class); // يتبنى بالـ mock الجديد
        $service->runAiGrading($gradeId);

        $grade = DB::table('exam_grades')->where('id', $gradeId)->first();
        $this->assertEquals(7.5, (float) $grade->marks_awarded);
        $this->assertSame('ai', $grade->source);

        $aiGrading = DB::table('exam_ai_gradings')->where('exam_grade_id', $gradeId)->first();
        $this->assertSame('completed', $aiGrading->status);
        $this->assertEquals(0.82, (float) $aiGrading->confidence);
        $this->assertSame(['Clear definition'], json_decode($aiGrading->strengths, true));

        $history = DB::table('exam_grade_history')->where('exam_grade_id', $gradeId)->first();
        $this->assertNotNull($history, 'AI grading should be recorded in exam_grade_history for auditability.');
        $this->assertSame('ai', $history->source);

        $attempt->refresh();
        $this->assertSame('graded', $attempt->status);
        $this->assertEquals(7.5, (float) $attempt->score);
        $this->assertEquals(75.0, (float) $attempt->percentage);
    }

    #[Test]
    public function an_ai_failure_leaves_the_grade_pending_instead_of_faking_a_score(): void
    {
        Queue::fake();
        [$attempt, , $gradeId] = $this->submittedEssayAttempt(aiEnabled: true);

        $fakeAi = Mockery::mock(AiExamGradingService::class);
        $fakeAi->shouldReceive('grade')->once()->andThrow(new \RuntimeException('AI provider timed out'));
        $this->app->instance(AiExamGradingService::class, $fakeAi);

        app(ExamGradingService::class)->runAiGrading($gradeId);

        $grade = DB::table('exam_grades')->where('id', $gradeId)->first();
        $this->assertNull($grade->marks_awarded, 'A failed AI call must never produce a fake score.');

        $aiGrading = DB::table('exam_ai_gradings')->where('exam_grade_id', $gradeId)->first();
        $this->assertSame('failed', $aiGrading->status);
        $this->assertStringContainsString('AI provider timed out', $aiGrading->error_message);

        $attempt->refresh();
        $this->assertSame('grading', $attempt->status, 'The attempt should stay pending for manual grading, not silently close.');
    }

    #[Test]
    public function ai_grading_never_overwrites_a_grade_the_instructor_already_set(): void
    {
        Queue::fake();
        [$attempt, $examQuestionId, $gradeId] = $this->submittedEssayAttempt(aiEnabled: true);

        // المدرس يتدخل يدويًا قبل ما الـ AI job يتنفذ (سيناريو واقعي: طابور
        // مزدحم، أو المدرس فتح المحاولة وصححها بنفسه الأول).
        $this->service->gradeManually($attempt, $examQuestionId, 9.0, 'Excellent work.', (object) ['id' => $this->staffId, 'user_id' => 0]);

        $fakeAi = Mockery::mock(AiExamGradingService::class);
        $fakeAi->shouldNotReceive('grade');
        $this->app->instance(AiExamGradingService::class, $fakeAi);

        app(ExamGradingService::class)->runAiGrading($gradeId);

        $grade = DB::table('exam_grades')->where('id', $gradeId)->first();
        $this->assertEquals(9.0, (float) $grade->marks_awarded);
        $this->assertSame('instructor', $grade->source);
    }

    #[Test]
    public function rubric_criteria_are_passed_through_to_the_ai_client(): void
    {
        Queue::fake();
        [, $examQuestionId, $gradeId] = $this->submittedEssayAttempt(aiEnabled: true);
        $questionId = DB::table('exam_questions')->where('id', $examQuestionId)->value('question_id');

        $rubricId = DB::table('exam_rubrics')->insertGetId([
            'question_id' => $questionId, 'created_by_academic_staff_id' => $this->staffId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('exam_rubric_criteria')->insert([
            ['exam_rubric_id' => $rubricId, 'label' => 'Correctness', 'max_points' => 6, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['exam_rubric_id' => $rubricId, 'label' => 'Clarity', 'max_points' => 4, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $fakeAi = Mockery::mock(AiExamGradingService::class);
        $fakeAi->shouldReceive('grade')
            ->once()
            ->withArgs(function ($question, $answerText, $maxMarks, $criteria) {
                $labels = array_map(fn ($c) => $c->label, $criteria);
                return count($criteria) === 2 && $labels === ['Correctness', 'Clarity'] && $maxMarks === 10.0;
            })
            ->andReturn([
                'marks_awarded' => 8.0, 'confidence' => 0.9, 'feedback' => 'Good.',
                'strengths' => [], 'missing_concepts' => [], 'reasoning_summary' => 'Solid.',
            ]);
        $this->app->instance(AiExamGradingService::class, $fakeAi);

        app(ExamGradingService::class)->runAiGrading($gradeId);

        $grade = DB::table('exam_grades')->where('id', $gradeId)->first();
        $this->assertEquals(8.0, (float) $grade->marks_awarded);
    }
}
