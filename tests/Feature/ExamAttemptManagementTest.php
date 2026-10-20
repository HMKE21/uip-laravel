<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Services\ExamAttemptManagementService;
use App\Services\ExamAttemptService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * إلغاء محاولة طالب + إعادة الامتحان (ExamAttemptManagementService) وتأثيرها
 * على ExamAttemptService::startAttempt() و activeAttemptForStudent().
 */
class ExamAttemptManagementTest extends TestCase
{
    use DatabaseTransactions;

    private ExamAttemptService $attemptService;
    private ExamAttemptManagementService $mgmt;
    private int $universityId = 1;
    private int $staffId;
    private int $questionBankId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attemptService = app(ExamAttemptService::class);
        $this->mgmt = app(ExamAttemptManagementService::class);

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

    private function exam(int $id): Exam
    {
        return Exam::findOrFail($id);
    }

    #[Test]
    public function cancelling_an_in_progress_attempt_ends_it_and_frees_the_exam_lock(): void
    {
        [$examId] = $this->makePublishedExamWithOneQuestion(60, 1);
        $studentId = $this->makeStudent();
        $attempt = $this->attemptService->startAttempt($examId, $studentId, $this->universityId)['attempt'];

        $this->mgmt->cancelAttempt($this->exam($examId), $attempt, 1, 'Network issue');

        $attempt->refresh();
        $this->assertSame('cancelled', $attempt->status);
        $this->assertSame('Network issue', $attempt->cancel_reason);
        $this->assertNotNull($attempt->cancelled_at);
        $this->assertNull(app(\App\Repositories\ExamAttemptRepository::class)->activeAttemptForStudent($studentId));
    }

    #[Test]
    public function a_cancelled_attempt_still_counts_unless_a_retake_is_allowed(): void
    {
        [$examId] = $this->makePublishedExamWithOneQuestion(60, 1);
        $studentId = $this->makeStudent();
        $attempt = $this->attemptService->startAttempt($examId, $studentId, $this->universityId)['attempt'];

        $this->mgmt->cancelAttempt($this->exam($examId), $attempt, 1, null, false);

        $this->expectException(\InvalidArgumentException::class);
        $this->attemptService->startAttempt($examId, $studentId, $this->universityId);
    }

    #[Test]
    public function cancel_with_retake_lets_the_student_start_a_new_attempt(): void
    {
        [$examId] = $this->makePublishedExamWithOneQuestion(60, 1);
        $studentId = $this->makeStudent();
        $first = $this->attemptService->startAttempt($examId, $studentId, $this->universityId)['attempt'];

        $res = $this->mgmt->cancelAttempt($this->exam($examId), $first, 1, 'Retake please', true);
        $this->assertNotNull($res['override']);

        $second = $this->attemptService->startAttempt($examId, $studentId, $this->universityId);
        $this->assertFalse($second['resumed']);
        $this->assertSame(2, (int) $second['attempt']->attempt_number);

        // ومفيش تالتة — الإعادة كانت محاولة واحدة بس.
        $this->attemptService->submitAttempt($second['attempt']);
        $this->expectException(\InvalidArgumentException::class);
        $this->attemptService->startAttempt($examId, $studentId, $this->universityId);
    }

    #[Test]
    public function granting_a_retake_after_the_exam_closed_opens_a_private_window(): void
    {
        [$examId] = $this->makePublishedExamWithOneQuestion(60, 1);
        $studentId = $this->makeStudent();
        $attempt = $this->attemptService->startAttempt($examId, $studentId, $this->universityId)['attempt'];
        $this->attemptService->submitAttempt($attempt);

        DB::table('exams')->where('id', $examId)->update(['status' => 'closed', 'end_at' => now()->subDay()]);

        try {
            $this->attemptService->startAttempt($examId, $studentId, $this->universityId);
            $this->fail('Expected the closed exam to reject the attempt.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not currently open', $e->getMessage());
        }

        $this->mgmt->grantRetake($this->exam($examId), $studentId, 1, now()->addDay(), 1, 'Medical excuse');

        $result = $this->attemptService->startAttempt($examId, $studentId, $this->universityId);
        $this->assertSame('in_progress', $result['attempt']->status);
    }

    #[Test]
    public function an_expired_private_window_blocks_the_retake(): void
    {
        [$examId] = $this->makePublishedExamWithOneQuestion(60, 1);
        $studentId = $this->makeStudent();
        $attempt = $this->attemptService->startAttempt($examId, $studentId, $this->universityId)['attempt'];
        $this->attemptService->submitAttempt($attempt);
        DB::table('exams')->where('id', $examId)->update(['status' => 'closed', 'end_at' => now()->subDay()]);

        $this->mgmt->grantRetake($this->exam($examId), $studentId, 1, now()->addHour(), 1);
        DB::table('exam_student_overrides')->where('exam_id', $examId)->update(['available_until' => now()->subMinute()]);

        $this->expectException(\InvalidArgumentException::class);
        $this->attemptService->startAttempt($examId, $studentId, $this->universityId);
    }

    #[Test]
    public function revoking_removes_only_unused_extra_attempts(): void
    {
        [$examId] = $this->makePublishedExamWithOneQuestion(60, 1);
        $studentId = $this->makeStudent();
        $exam = $this->exam($examId);

        $this->mgmt->grantRetake($exam, $studentId, 2, null, 1);
        $override = $this->mgmt->revokeRetake($exam, $studentId, 1);

        $this->assertSame(0, (int) $override->extra_attempts);
    }

    #[Test]
    public function granting_to_a_student_from_another_university_is_rejected(): void
    {
        [$examId] = $this->makePublishedExamWithOneQuestion(60, 1);
        $studentId = $this->makeStudent();
        DB::table('students')->where('id', $studentId)->update(['university_id' => 999999]);

        $this->expectException(\InvalidArgumentException::class);
        $this->mgmt->grantRetake($this->exam($examId), $studentId, 1, null, 1);
    }

    #[Test]
    public function cancelling_twice_is_rejected(): void
    {
        [$examId] = $this->makePublishedExamWithOneQuestion(60, 2);
        $studentId = $this->makeStudent();
        $attempt = $this->attemptService->startAttempt($examId, $studentId, $this->universityId)['attempt'];
        $this->mgmt->cancelAttempt($this->exam($examId), $attempt, 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->mgmt->cancelAttempt($this->exam($examId), $attempt->refresh(), 1);
    }
}
