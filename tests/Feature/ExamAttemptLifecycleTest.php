<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamGradeRepository;
use App\Repositories\ExamRepository;
use App\Repositories\ExamTargetRepository;
use App\Services\ExamAttemptService;
use App\Services\ExamGradingService;
use App\Services\ExamSecurityService;
use App\Services\QuestionSelectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end test للمسار الكامل: نشر امتحان → طالب يبدأ محاولة → يجاوب →
 * الوقت يخلص → auto-submit → التصحيح يتحسب → النتيجة تظهر.
 *
 * ده بيغطي التكامل بين ExamAttemptService (بدء/تايمر/auto-submit) و
 * QuestionSelectionService (تجميد الأسئلة وقت بدء المحاولة) و
 * ExamGradingService (التصحيح) مع بعض — مش كل واحدة لوحدها، عشان ده
 * بالظبط المكان اللي رجّة صغيرة في أي واحدة منهم ممكن تكسر المسار كله
 * من غير ما test منفصل على كل service يلاحظ.
 *
 * نفس ملاحظة البيئة في باقي ملفات الاختبار: DatabaseTransactions بدل
 * RefreshDatabase (migrations الجداول الأساسية مش جزء من هذا الباتش).
 */
class ExamAttemptLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private ExamAttemptService $attemptService;
    private ExamGradingService $gradingService;
    private int $universityId = 1;
    private int $staffId;
    private int $questionBankId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attemptService = app(ExamAttemptService::class);
        $this->gradingService = app(ExamGradingService::class);

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

    /** بيعمل امتحان منشور بمدة قصيرة + سؤال MCQ واحد يدوي متاح لكل الجامعة. */
    private function makePublishedExamWithOneQuestion(int $durationMinutes = 60, int $maxAttempts = 1): array
    {
        $examId = DB::table('exams')->insertGetId([
            'university_id' => $this->universityId, 'created_by_academic_staff_id' => $this->staffId,
            'title' => 'Midterm', 'duration_minutes' => $durationMinutes, 'max_attempts' => $maxAttempts,
            'status' => 'published', 'total_marks' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('exam_targets')->insert([
            'exam_id' => $examId, 'created_at' => now(), 'updated_at' => now(),
        ]); // صف wildcard = الجامعة كلها مؤهلة

        $questionId = DB::table('questions')->insertGetId([
            'question_bank_id' => $this->questionBankId, 'type' => 'mcq', 'prompt' => 'Q?',
            'marks' => 10, 'created_by_academic_staff_id' => $this->staffId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $correctOptionId = DB::table('question_options')->insertGetId([
            'question_id' => $questionId, 'option_text' => 'Correct', 'is_correct' => true,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('question_options')->insert([
            'question_id' => $questionId, 'option_text' => 'Wrong', 'is_correct' => false,
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $examQuestionId = DB::table('exam_questions')->insertGetId([
            'exam_id' => $examId, 'question_id' => $questionId, 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$examId, $examQuestionId, $correctOptionId];
    }

    #[Test]
    public function starting_an_attempt_freezes_the_exams_questions_for_that_attempt(): void
    {
        [$examId, $examQuestionId] = $this->makePublishedExamWithOneQuestion();
        $studentId = $this->makeStudent();

        $result = $this->attemptService->startAttempt($examId, $studentId, $this->universityId);

        $this->assertFalse($result['resumed']);
        $attempt = $result['attempt'];
        $this->assertSame('in_progress', $attempt->status);
        $this->assertNotNull($attempt->expires_at);

        $frozenQuestionIds = DB::table('exam_attempt_questions')
            ->where('exam_attempt_id', $attempt->id)->pluck('exam_question_id')->all();
        $this->assertSame([$examQuestionId], $frozenQuestionIds);
    }

    #[Test]
    public function a_student_who_is_not_targeted_cannot_start_an_attempt(): void
    {
        $examId = DB::table('exams')->insertGetId([
            'university_id' => $this->universityId, 'created_by_academic_staff_id' => $this->staffId,
            'title' => 'Restricted Exam', 'duration_minutes' => 60, 'max_attempts' => 1,
            'status' => 'published', 'total_marks' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // target: faculty_id=999 بس — الطالب اللي هنعمله معندوش faculty_id
        DB::table('faculties')->insertOrIgnore(['id' => 999, 'name' => 'Some Other Faculty']);
        DB::table('exam_targets')->insert([
            'exam_id' => $examId, 'faculty_id' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $studentId = $this->makeStudent();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not eligible');
        $this->attemptService->startAttempt($examId, $studentId, $this->universityId);
    }

    #[Test]
    public function resuming_returns_the_same_active_attempt_instead_of_creating_a_new_one(): void
    {
        [$examId] = $this->makePublishedExamWithOneQuestion();
        $studentId = $this->makeStudent();

        $first = $this->attemptService->startAttempt($examId, $studentId, $this->universityId);
        $second = $this->attemptService->startAttempt($examId, $studentId, $this->universityId);

        $this->assertFalse($first['resumed']);
        $this->assertTrue($second['resumed']);
        $this->assertSame($first['attempt']->id, $second['attempt']->id);
        $this->assertSame(1, DB::table('exam_attempts')->where('student_id', $studentId)->count());
    }

    #[Test]
    public function a_correct_answer_survives_the_full_submit_and_grade_pipeline(): void
    {
        [$examId, $examQuestionId, $correctOptionId] = $this->makePublishedExamWithOneQuestion();
        $studentId = $this->makeStudent();

        $result = $this->attemptService->startAttempt($examId, $studentId, $this->universityId);
        $attempt = $result['attempt'];

        $this->attemptService->saveAnswer($attempt, $examQuestionId, ['selected_option_ids' => [$correctOptionId]]);
        $attempt = $this->attemptService->submitAttempt($attempt->fresh());

        // الامتحان ده كله أسئلة auto-gradable (MCQ)، فمفروض يوصل لـ 'graded'
        // فورًا من غير ما يستنى تدخل مدرس — 'submitted' هنا مرحلة عابرة بس.
        $this->assertFalse((bool) $attempt->auto_submitted, 'A manual submit must not be flagged as auto-submitted.');
        $this->assertSame('graded', $attempt->status);
        $this->assertEquals(10.0, (float) $attempt->score);
        $this->assertEquals(100.0, (float) $attempt->percentage);
    }

    #[Test]
    public function an_expired_attempt_is_auto_submitted_and_graded_from_whatever_was_saved(): void
    {
        [$examId, $examQuestionId, $correctOptionId] = $this->makePublishedExamWithOneQuestion(durationMinutes: 30);
        $studentId = $this->makeStudent();

        $result = $this->attemptService->startAttempt($examId, $studentId, $this->universityId);
        $attempt = $result['attempt'];
        $this->attemptService->saveAnswer($attempt, $examQuestionId, ['selected_option_ids' => [$correctOptionId]]);

        // محاكاة "الوقت خلص" من غير ما نستنى فعليًا: نرجّع expires_at للماضي
        // (زي طالب فتح المتصفح تاني بعد ما وقته خلص فعلًا على السيرفر).
        DB::table('exam_attempts')->where('id', $attempt->id)->update(['expires_at' => now()->subMinute()]);

        $reloaded = ExamAttempt::findOrFail($attempt->id);
        $result = $this->attemptService->enforceTimer($reloaded);

        // الامتحان كله MCQ auto-gradable، فبعد finalizeSubmission() المحاولة
        // توصل 'graded' فورًا — بس علم auto_submitted لازم يفضل true عشان
        // نقدر نميّز إن ده حصل بسبب انتهاء الوقت مش تسليم يدوي من الطالب.
        $this->assertSame('graded', $result->status);
        $this->assertTrue((bool) $result->auto_submitted);
        $this->assertNotNull($result->submitted_at);
        $this->assertEquals(10.0, (float) $result->score, 'The answer saved before expiry should still be graded.');

        $event = DB::table('exam_security_events')
            ->where('exam_attempt_id', $attempt->id)->where('event_type', 'auto_submitted')->first();
        $this->assertNotNull($event, 'Auto-submit should be logged as a system security event.');
    }

    #[Test]
    public function saving_an_answer_after_the_timer_expired_is_rejected_not_silently_accepted(): void
    {
        // ده الـ regression الأخطر اللي اتقلق منه في التحليل: لو الطالب
        // (أو الفرونت إند بتاعه) بعت save answer بعد ما التايمر خلص فعليًا
        // على السيرفر، لازم يترفض — مش يتقبل وكأن حاجة متغيّرتش.
        [$examId, $examQuestionId, $correctOptionId] = $this->makePublishedExamWithOneQuestion(durationMinutes: 30);
        $studentId = $this->makeStudent();

        $attempt = $this->attemptService->startAttempt($examId, $studentId, $this->universityId)['attempt'];
        DB::table('exam_attempts')->where('id', $attempt->id)->update(['expires_at' => now()->subMinute()]);
        $expired = ExamAttempt::findOrFail($attempt->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->attemptService->saveAnswer($expired, $examQuestionId, ['selected_option_ids' => [$correctOptionId]]);
    }

    #[Test]
    public function the_auto_submit_sweep_command_catches_expired_attempts_across_students(): void
    {
        [$examId, $examQuestionId, $correctOptionId] = $this->makePublishedExamWithOneQuestion(durationMinutes: 30, maxAttempts: 5);

        $expiredStudentId = $this->makeStudent();
        $stillActiveStudentId = $this->makeStudent();

        $expiredAttempt = $this->attemptService->startAttempt($examId, $expiredStudentId, $this->universityId)['attempt'];
        $activeAttempt = $this->attemptService->startAttempt($examId, $stillActiveStudentId, $this->universityId)['attempt'];

        DB::table('exam_attempts')->where('id', $expiredAttempt->id)->update(['expires_at' => now()->subMinutes(5)]);

        $exitCode = Artisan::call('exams:auto-submit-expired');

        $this->assertSame(0, $exitCode);
        $expired = ExamAttempt::find($expiredAttempt->id);
        $this->assertSame('graded', $expired->status, 'Fully auto-gradable exam should reach graded, not just auto_submitted.');
        $this->assertTrue((bool) $expired->auto_submitted);
        $this->assertSame('in_progress', ExamAttempt::find($activeAttempt->id)->status, 'An attempt whose timer has not expired must not be touched by the sweep.');
    }

    #[Test]
    public function a_student_cannot_start_a_second_attempt_after_reaching_max_attempts(): void
    {
        [$examId, $examQuestionId, $correctOptionId] = $this->makePublishedExamWithOneQuestion(maxAttempts: 1);
        $studentId = $this->makeStudent();

        $attempt = $this->attemptService->startAttempt($examId, $studentId, $this->universityId)['attempt'];
        $this->attemptService->saveAnswer($attempt, $examQuestionId, ['selected_option_ids' => [$correctOptionId]]);
        $this->attemptService->submitAttempt($attempt->fresh());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum number of attempts');
        $this->attemptService->startAttempt($examId, $studentId, $this->universityId);
    }
}
