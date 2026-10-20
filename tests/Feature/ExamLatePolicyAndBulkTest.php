<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Services\ExamAttemptManagementService;
use App\Services\ExamAttemptService;
use App\Services\UipJwtService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * سياسة التسليم المتأخر (فترة سماح + خصم) + الوقت الإضافي + الإجراءات الجماعية + بحث الطلاب الغايبين.
 * جزء على مستوى الخدمات وجزء على مستوى الـ HTTP endpoints (الصلاحيات والـ validation والـ IDOR).
 */
class ExamLatePolicyAndBulkTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/v1/exam-system';

    private int $uniA = 1;
    private int $uniB = 2;

    private int $staffA;      // صاحب الامتحان
    private int $staffB;      // مدرس تاني (غريب)
    private int $staffAUser;
    private int $staffBUser;

    private int $studentA;    // طالب بيدخل الامتحان
    private int $studentB;    // طالب تاني من نفس الجامعة
    private int $studentAUser;
    private int $studentBUser;

    private int $examId;
    private int $examQuestionId;
    private int $correctOptionId;
    private int $bankA;
    private int $bankB;

    protected function setUp(): void
    {
        parent::setUp();

        // env() بيقرا من $_ENV/$_SERVER — بنحط السر في الاتنين.
        $_ENV['JWT_SECRET'] = $_SERVER['JWT_SECRET'] = 'test-secret-for-exam-endpoint-tests';
        putenv('JWT_SECRET=test-secret-for-exam-endpoint-tests');

        DB::table('universities')->insert([
            ['id' => $this->uniA, 'name' => 'Uni A'],
            ['id' => $this->uniB, 'name' => 'Uni B'],
        ]);

        [$this->staffAUser, $this->staffA] = $this->makeStaff($this->uniA);
        [$this->staffBUser, $this->staffB] = $this->makeStaff($this->uniA);
        [$this->studentAUser, $this->studentA] = $this->makeStudent($this->uniA);
        [$this->studentBUser, $this->studentB] = $this->makeStudent($this->uniA);

        $this->bankA = $this->makeBank($this->staffA);
        $this->bankB = $this->makeBank($this->staffB);

        [$this->examId, $this->examQuestionId, $this->correctOptionId] = $this->makePublishedExam($this->staffA, $this->bankA);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeUser(string $prefix): int
    {
        return DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'full_name' => $prefix . ' ' . uniqid(),
            'email' => $prefix . '_' . uniqid() . '@test.local', 'password_hash' => bcrypt('secret'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0:int,1:int} [userId, staffId] */
    private function makeStaff(int $universityId): array
    {
        $userId = $this->makeUser('staff');
        $staffId = DB::table('academic_staff')->insertGetId([
            'user_id' => $userId, 'university_id' => $universityId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$userId, $staffId];
    }

    /** @return array{0:int,1:int} [userId, studentId] */
    private function makeStudent(int $universityId): array
    {
        $userId = $this->makeUser('student');
        $studentId = DB::table('students')->insertGetId([
            'user_id' => $userId, 'university_id' => $universityId,
            'student_number' => 'S' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$userId, $studentId];
    }

    private function makeBank(int $staffId): int
    {
        return DB::table('question_banks')->insertGetId([
            'university_id' => $this->uniA, 'created_by_academic_staff_id' => $staffId,
            'title' => 'Bank ' . uniqid(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeQuestion(int $bankId, int $staffId): array
    {
        $questionId = DB::table('questions')->insertGetId([
            'question_bank_id' => $bankId, 'type' => 'mcq', 'prompt' => 'Q?', 'marks' => 10,
            'created_by_academic_staff_id' => $staffId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $correct = DB::table('question_options')->insertGetId([
            'question_id' => $questionId, 'option_text' => 'Correct', 'is_correct' => true,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('question_options')->insert([
            'question_id' => $questionId, 'option_text' => 'Wrong', 'is_correct' => false,
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$questionId, $correct];
    }

    /** @return array{0:int,1:int,2:int} [examId, examQuestionId, correctOptionId] */
    private function makePublishedExam(int $staffId, int $bankId): array
    {
        $examId = DB::table('exams')->insertGetId([
            'university_id' => $this->uniA, 'created_by_academic_staff_id' => $staffId,
            'title' => 'Midterm', 'duration_minutes' => 60, 'max_attempts' => 1,
            'status' => 'published', 'total_marks' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('exam_targets')->insert(['exam_id' => $examId, 'created_at' => now(), 'updated_at' => now()]);

        [$questionId, $correct] = $this->makeQuestion($bankId, $staffId);
        $examQuestionId = DB::table('exam_questions')->insertGetId([
            'exam_id' => $examId, 'question_id' => $questionId, 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$examId, $examQuestionId, $correct];
    }

    private function tokenFor(int $userId, string $role): array
    {
        return ['Authorization' => 'Bearer ' . UipJwtService::encode(['sub' => $userId, 'role' => $role], 600)];
    }

    private function asStaffA(): array { return $this->tokenFor($this->staffAUser, 'academic_staff'); }
    private function asStaffB(): array { return $this->tokenFor($this->staffBUser, 'academic_staff'); }
    private function asStudentA(): array { return $this->tokenFor($this->studentAUser, 'student'); }
    private function asStudentB(): array { return $this->tokenFor($this->studentBUser, 'student'); }

    private function startAttemptFor(int $studentId): int
    {
        return app(ExamAttemptService::class)->startAttempt($this->examId, $studentId, $this->uniA)['attempt']->id;
    }

    private function submittedAttemptFor(int $studentId): int
    {
        $svc = app(ExamAttemptService::class);
        $attempt = $svc->startAttempt($this->examId, $studentId, $this->uniA)['attempt'];
        $svc->saveAnswer($attempt, $this->examQuestionId, ['selected_option_ids' => [$this->correctOptionId]]);
        $svc->submitAttempt($attempt->fresh());
        return $attempt->id;
    }

    // ------------------------------------------------------------------
    // Helpers for this suite
    // ------------------------------------------------------------------

    private function svc(): ExamAttemptService
    {
        return app(ExamAttemptService::class);
    }

    private function setPolicy(int $grace, float $penalty): void
    {
        DB::table('exams')->where('id', $this->examId)->update(['late_grace_minutes' => $grace, 'late_penalty_percent' => $penalty]);
    }

    private function expireBy(int $attemptId, float $minutesAgo): void
    {
        DB::table('exam_attempts')->where('id', $attemptId)->update([
            'expires_at' => now()->subSeconds((int) ($minutesAgo * 60)),
            'last_activity_at' => now()->subSeconds((int) ($minutesAgo * 60) + 30),
        ]);
    }

    private function mgmt(): ExamAttemptManagementService
    {
        return app(ExamAttemptManagementService::class);
    }

    private function exam(): Exam
    {
        return Exam::findOrFail($this->examId);
    }

    // ------------------------------------------------------------------
    // Late-submission policy
    // ------------------------------------------------------------------

    #[Test]
    public function without_a_grace_period_the_exam_still_closes_hard(): void
    {
        $attempt = $this->svc()->startAttempt($this->examId, $this->studentA, $this->uniA)['attempt'];
        $this->expireBy($attempt->id, 1);

        $attempt = $this->svc()->enforceTimer($attempt->fresh());

        $this->assertSame('graded', $attempt->fresh()->status);
        $this->assertFalse((bool) $attempt->fresh()->is_late);
    }

    #[Test]
    public function inside_the_grace_period_the_attempt_stays_open_and_a_late_submit_is_penalised(): void
    {
        $this->setPolicy(10, 20);
        $attempt = $this->svc()->startAttempt($this->examId, $this->studentA, $this->uniA)['attempt'];
        $this->svc()->saveAnswer($attempt->fresh(), $this->examQuestionId, ['selected_option_ids' => [$this->correctOptionId]]);
        $this->expireBy($attempt->id, 3); // 3 min past the deadline, 7 min of grace left

        $open = $this->svc()->enforceTimer($attempt->fresh());
        $this->assertSame('in_progress', $open->status);
        $this->assertTrue($open->inGrace());
        $this->assertSame(0, $open->remainingSeconds());
        $this->assertGreaterThan(0, $open->graceRemainingSeconds());

        $done = $this->svc()->submitAttempt($open->fresh())->fresh();
        $this->assertTrue((bool) $done->is_late);
        $this->assertSame('graded', $done->status);
        $this->assertEquals(10.0, (float) $done->score_before_penalty);
        $this->assertEquals(8.0, (float) $done->score);       // 10 - 20%
        $this->assertEquals(80.0, (float) $done->percentage);
    }

    #[Test]
    public function an_on_time_submit_inside_a_grace_policy_is_not_penalised(): void
    {
        $this->setPolicy(10, 20);
        $attempt = $this->svc()->startAttempt($this->examId, $this->studentA, $this->uniA)['attempt'];
        $this->svc()->saveAnswer($attempt->fresh(), $this->examQuestionId, ['selected_option_ids' => [$this->correctOptionId]]);

        $done = $this->svc()->submitAttempt($attempt->fresh())->fresh();

        $this->assertFalse((bool) $done->is_late);
        $this->assertEquals(10.0, (float) $done->score);
        $this->assertNull($done->score_before_penalty);
    }

    #[Test]
    public function after_the_grace_period_the_attempt_is_auto_submitted(): void
    {
        $this->setPolicy(5, 50);
        $attempt = $this->svc()->startAttempt($this->examId, $this->studentA, $this->uniA)['attempt'];
        $this->svc()->saveAnswer($attempt->fresh(), $this->examQuestionId, ['selected_option_ids' => [$this->correctOptionId]]);
        $this->expireBy($attempt->id, 10); // grace (5 min) is over; the student was last active before the deadline

        $this->svc()->enforceTimer($attempt->fresh());

        $row = $attempt->fresh();
        $this->assertSame('graded', $row->status);
        $this->assertTrue((bool) $row->auto_submitted);
        // last activity was before the deadline, so this is a plain timeout, not a late submission
        $this->assertFalse((bool) $row->is_late);
        $this->assertEquals(10.0, (float) $row->score);
    }

    #[Test]
    public function saving_answers_is_allowed_in_grace_but_not_after_it(): void
    {
        $this->setPolicy(5, 0);
        $attempt = $this->svc()->startAttempt($this->examId, $this->studentA, $this->uniA)['attempt'];
        $this->expireBy($attempt->id, 2);
        $this->svc()->saveAnswer($attempt->fresh(), $this->examQuestionId, ['selected_option_ids' => [$this->correctOptionId]]);
        $this->assertSame('in_progress', $attempt->fresh()->status);

        $this->expireBy($attempt->id, 9);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->saveAnswer($attempt->fresh(), $this->examQuestionId, ['selected_option_ids' => [$this->correctOptionId]]);
    }

    #[Test]
    public function the_exam_lock_stays_on_during_grace_and_off_after_it(): void
    {
        $this->setPolicy(5, 10);
        $repo = app(\App\Repositories\ExamAttemptRepository::class);
        $attempt = $this->svc()->startAttempt($this->examId, $this->studentA, $this->uniA)['attempt'];

        $this->expireBy($attempt->id, 2);
        $this->assertNotNull($repo->activeAttemptForStudent($this->studentA));

        $this->expireBy($attempt->id, 9);
        $this->assertNull($repo->activeAttemptForStudent($this->studentA));
    }

    #[Test]
    public function the_sweep_leaves_attempts_in_grace_alone(): void
    {
        $this->setPolicy(5, 10);
        $attempt = $this->svc()->startAttempt($this->examId, $this->studentA, $this->uniA)['attempt'];
        $this->expireBy($attempt->id, 2);
        $this->assertSame(0, $this->svc()->autoSubmitAllExpired());
        $this->assertSame('in_progress', $attempt->fresh()->status);

        $this->expireBy($attempt->id, 9);
        $this->assertSame(1, $this->svc()->autoSubmitAllExpired());
    }

    #[Test]
    public function the_penalty_is_not_applied_twice_when_a_grade_is_edited_later(): void
    {
        $this->setPolicy(10, 25);
        $attempt = $this->svc()->startAttempt($this->examId, $this->studentA, $this->uniA)['attempt'];
        $this->svc()->saveAnswer($attempt->fresh(), $this->examQuestionId, ['selected_option_ids' => [$this->correctOptionId]]);
        $this->expireBy($attempt->id, 1);
        $this->svc()->submitAttempt($this->svc()->enforceTimer($attempt->fresh()));

        app(\App\Services\ExamGradingService::class)->autoGradeAttempt($attempt->fresh());

        $this->assertEquals(7.5, (float) $attempt->fresh()->score);
        $this->assertEquals(10.0, (float) $attempt->fresh()->score_before_penalty);
    }

    #[Test]
    public function the_instructor_sets_the_policy_when_creating_the_exam_and_it_is_validated(): void
    {
        $h = $this->asStaffA();
        $ok = $this->withHeaders($h)->postJson(self::BASE . '/exams', [
            'title' => 'With grace', 'duration_minutes' => 30, 'late_grace_minutes' => 15, 'late_penalty_percent' => 10,
        ]);
        $ok->assertStatus(201);
        $id = $ok->json('data.id');
        $this->assertSame(15, (int) DB::table('exams')->where('id', $id)->value('late_grace_minutes'));
        $this->assertEquals(10.0, (float) DB::table('exams')->where('id', $id)->value('late_penalty_percent'));

        $this->withHeaders($h)->postJson(self::BASE . '/exams', ['title' => 'x', 'duration_minutes' => 30, 'late_penalty_percent' => 101])->assertStatus(422);
        $this->withHeaders($h)->postJson(self::BASE . '/exams', ['title' => 'x', 'duration_minutes' => 30, 'late_grace_minutes' => 500])->assertStatus(422);

        // خصم من غير فترة سماح بيتصفّر
        $noGrace = $this->withHeaders($h)->postJson(self::BASE . '/exams', ['title' => 'No grace', 'duration_minutes' => 30, 'late_penalty_percent' => 40]);
        $this->assertEquals(0.0, (float) DB::table('exams')->where('id', $noGrace->json('data.id'))->value('late_penalty_percent'));
    }

    // ------------------------------------------------------------------
    // Extra time
    // ------------------------------------------------------------------

    #[Test]
    public function extra_time_extends_a_running_attempt(): void
    {
        $attempt = $this->svc()->startAttempt($this->examId, $this->studentA, $this->uniA)['attempt'];
        $before = $attempt->fresh()->expires_at;

        $res = $this->withHeaders($this->asStaffA())
            ->postJson(self::BASE . "/exams/{$this->examId}/attempts/{$attempt->id}/extra-time", ['minutes' => 15, 'reason' => 'Bad internet']);

        $res->assertStatus(200);
        $row = $attempt->fresh();
        $this->assertSame(15, (int) $row->extra_time_minutes);
        $this->assertEquals(15, (int) $before->diffInMinutes($row->expires_at));
    }

    #[Test]
    public function extra_time_is_rejected_for_a_finished_attempt_and_for_bad_input(): void
    {
        $done = $this->submittedAttemptFor($this->studentA);
        $h = $this->asStaffA();

        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$this->examId}/attempts/{$done}/extra-time", ['minutes' => 5])->assertStatus(422);

        $running = $this->startAttemptFor($this->studentB);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$this->examId}/attempts/{$running}/extra-time", ['minutes' => 0])->assertStatus(422);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$this->examId}/attempts/{$running}/extra-time", ['minutes' => 241])->assertStatus(422);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$this->examId}/attempts/{$running}/extra-time", [])->assertStatus(422);
    }

    #[Test]
    public function another_instructor_cannot_add_extra_time_or_use_bulk_endpoints(): void
    {
        $running = $this->startAttemptFor($this->studentA);
        $h = $this->asStaffB();
        $id = $this->examId;

        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/attempts/{$running}/extra-time", ['minutes' => 5])->assertStatus(404);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/attempts/bulk-extra-time", ['attempt_ids' => [$running], 'minutes' => 5])->assertStatus(404);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/attempts/bulk-cancel", ['attempt_ids' => [$running]])->assertStatus(404);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/retake/bulk", ['student_ids' => [$this->studentA]])->assertStatus(404);
        $this->withHeaders($h)->getJson(self::BASE . "/exams/{$id}/eligible-students")->assertStatus(404);

        $this->assertSame('in_progress', DB::table('exam_attempts')->where('id', $running)->value('status'));
        $this->assertSame(0, (int) DB::table('exam_attempts')->where('id', $running)->value('extra_time_minutes'));
    }

    #[Test]
    public function students_cannot_use_the_new_instructor_endpoints(): void
    {
        $h = $this->asStudentA();
        $id = $this->examId;
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/attempts/1/extra-time", ['minutes' => 5])->assertStatus(403);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/attempts/bulk-cancel", ['attempt_ids' => [1]])->assertStatus(403);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/attempts/bulk-extra-time", ['attempt_ids' => [1], 'minutes' => 5])->assertStatus(403);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/retake/bulk", ['student_ids' => [1]])->assertStatus(403);
        $this->withHeaders($h)->getJson(self::BASE . "/exams/{$id}/eligible-students")->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Bulk actions
    // ------------------------------------------------------------------

    #[Test]
    public function bulk_extra_time_reports_per_item_results_and_never_blocks_the_rest(): void
    {
        $a = $this->startAttemptFor($this->studentA);
        $b = $this->submittedAttemptFor($this->studentB); // already finished -> should fail alone
        [$otherExamId] = $this->makePublishedExam($this->staffA, $this->bankA);
        $foreign = app(ExamAttemptService::class)->startAttempt($otherExamId, $this->studentA, $this->uniA)['attempt']->id;

        $res = $this->withHeaders($this->asStaffA())->postJson(self::BASE . "/exams/{$this->examId}/attempts/bulk-extra-time", [
            'attempt_ids' => [$a, $b, $foreign, 999999], 'minutes' => 10,
        ]);

        $res->assertStatus(200);
        $this->assertSame([$a], $res->json('data.succeeded'));
        $failedIds = array_column($res->json('data.failed'), 'id');
        sort($failedIds);
        $expected = [$b, $foreign, 999999];
        sort($expected);
        $this->assertSame($expected, $failedIds);
        $this->assertSame(10, (int) DB::table('exam_attempts')->where('id', $a)->value('extra_time_minutes'));
        // attempt of another exam is untouched
        $this->assertSame(0, (int) DB::table('exam_attempts')->where('id', $foreign)->value('extra_time_minutes'));
    }

    #[Test]
    public function bulk_cancel_with_retake_cancels_everyone_and_lets_them_start_again(): void
    {
        $a = $this->startAttemptFor($this->studentA);
        $b = $this->startAttemptFor($this->studentB);

        $res = $this->withHeaders($this->asStaffA())->postJson(self::BASE . "/exams/{$this->examId}/attempts/bulk-cancel", [
            'attempt_ids' => [$a, $b], 'reason' => 'Network outage', 'allow_retake' => true,
        ]);

        $res->assertStatus(200);
        $this->assertEqualsCanonicalizing([$a, $b], $res->json('data.succeeded'));
        $this->assertSame('cancelled', DB::table('exam_attempts')->where('id', $a)->value('status'));
        $this->assertSame('cancelled', DB::table('exam_attempts')->where('id', $b)->value('status'));
        $this->assertNotNull($this->startAttemptFor($this->studentA));
        $this->assertNotNull($this->startAttemptFor($this->studentB));
    }

    #[Test]
    public function bulk_cancel_returns_422_when_nothing_could_be_processed(): void
    {
        $res = $this->withHeaders($this->asStaffA())->postJson(self::BASE . "/exams/{$this->examId}/attempts/bulk-cancel", ['attempt_ids' => [999998, 999999]]);
        $res->assertStatus(422);
        $this->assertCount(2, $res->json('errors.failed'));
    }

    #[Test]
    public function bulk_endpoints_validate_their_input(): void
    {
        $h = $this->asStaffA();
        $id = $this->examId;
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/attempts/bulk-cancel", [])->assertStatus(422);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/attempts/bulk-cancel", ['attempt_ids' => []])->assertStatus(422);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/attempts/bulk-cancel", ['attempt_ids' => range(1, 201)])->assertStatus(422);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/attempts/bulk-extra-time", ['attempt_ids' => [1]])->assertStatus(422);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/retake/bulk", ['student_ids' => 'nope'])->assertStatus(422);
    }

    #[Test]
    public function bulk_retake_grants_to_every_eligible_student_and_reports_the_ineligible(): void
    {
        $outsider = DB::table('students')->insertGetId([
            'user_id' => $this->makeUser('outsider'), 'university_id' => $this->uniB,
            'student_number' => 'X' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->withHeaders($this->asStaffA())->postJson(self::BASE . "/exams/{$this->examId}/retake/bulk", [
            'student_ids' => [$this->studentA, $this->studentB, $outsider],
        ]);

        $res->assertStatus(200);
        $this->assertEqualsCanonicalizing([$this->studentA, $this->studentB], $res->json('data.succeeded'));
        $this->assertSame([$outsider], array_column($res->json('data.failed'), 'id'));
    }

    // ------------------------------------------------------------------
    // Find a student who missed the exam
    // ------------------------------------------------------------------

    #[Test]
    public function the_search_finds_students_without_attempts_and_filters_by_text(): void
    {
        $this->startAttemptFor($this->studentA);
        $h = $this->asStaffA();

        $all = $this->withHeaders($h)->getJson(self::BASE . "/exams/{$this->examId}/eligible-students")->assertStatus(200);
        $this->assertContains($this->studentA, array_column($all->json('data'), 'id'));

        $missing = $this->withHeaders($h)->getJson(self::BASE . "/exams/{$this->examId}/eligible-students?without_attempts=1")->assertStatus(200);
        $ids = array_column($missing->json('data'), 'id');
        $this->assertNotContains($this->studentA, $ids);
        $this->assertContains($this->studentB, $ids);

        $number = DB::table('students')->where('id', $this->studentB)->value('student_number');
        $byNumber = $this->withHeaders($h)->getJson(self::BASE . "/exams/{$this->examId}/eligible-students?q=" . urlencode($number))->assertStatus(200);
        $this->assertSame([$this->studentB], array_column($byNumber->json('data'), 'id'));

        // wildcard characters in the query are literal, not SQL wildcards
        $weird = $this->withHeaders($h)->getJson(self::BASE . "/exams/{$this->examId}/eligible-students?q=" . urlencode('%'))->assertStatus(200);
        $this->assertSame([], $weird->json('data'));
    }
}

