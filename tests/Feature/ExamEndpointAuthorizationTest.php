<?php

namespace Tests\Feature;

use App\Services\ExamAttemptService;
use App\Services\UipJwtService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * اختبارات على مستوى الـ HTTP endpoints نفسها (مش الـ services) — الصلاحيات
 * والـ validation: مين يقدر يوصل لإيه. الهدف الأساسي: مدرس/طالب يوصل لامتحان
 * أو محاولة مش بتاعته (IDOR) لازم يتقفل بـ 404، ودور غلط لازم 403.
 *
 * بتستخدم JWT حقيقي (UipJwtService::encode) عن طريق نفس uip.auth middleware،
 * فبتغطي السلسلة كاملة: middleware -> controller -> ownership check.
 */
class ExamEndpointAuthorizationTest extends TestCase
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
    // Authentication + role gates
    // ------------------------------------------------------------------

    #[Test]
    public function every_exam_endpoint_requires_a_token(): void
    {
        $this->getJson(self::BASE . '/exams')->assertStatus(401);
        $this->getJson(self::BASE . "/exams/{$this->examId}/attempts")->assertStatus(401);
        $this->getJson(self::BASE . '/my-exams')->assertStatus(401);
        $this->putJson(self::BASE . "/attempts/1/answers/{$this->examQuestionId}", [])->assertStatus(401);
        $this->postJson(self::BASE . '/attempts/1/submit')->assertStatus(401);
    }

    #[Test]
    public function a_student_token_cannot_use_instructor_endpoints(): void
    {
        $h = $this->asStudentA();
        $this->withHeaders($h)->getJson(self::BASE . '/exams')->assertStatus(403);
        $this->withHeaders($h)->postJson(self::BASE . '/exams', ['title' => 'x'])->assertStatus(403);
        $this->withHeaders($h)->getJson(self::BASE . "/exams/{$this->examId}/attempts")->assertStatus(403);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$this->examId}/students/{$this->studentB}/retake")->assertStatus(403);
        $this->withHeaders($h)->getJson(self::BASE . '/question-banks')->assertStatus(403);
    }

    #[Test]
    public function an_instructor_token_cannot_use_student_endpoints(): void
    {
        $h = $this->asStaffA();
        $this->withHeaders($h)->getJson(self::BASE . '/my-exams')->assertStatus(403);
        $this->withHeaders($h)->postJson(self::BASE . "/my-exams/{$this->examId}/attempts")->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Instructor A vs. instructor B (IDOR on exams)
    // ------------------------------------------------------------------

    #[Test]
    public function another_instructor_cannot_see_or_modify_my_exam(): void
    {
        $h = $this->asStaffB();
        $id = $this->examId;

        $this->withHeaders($h)->getJson(self::BASE . "/exams/{$id}")->assertStatus(404);
        $this->withHeaders($h)->patchJson(self::BASE . "/exams/{$id}", ['title' => 'Hijacked'])->assertStatus(404);
        $this->withHeaders($h)->deleteJson(self::BASE . "/exams/{$id}")->assertStatus(404);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/unpublish")->assertStatus(404);
        $this->withHeaders($h)->getJson(self::BASE . "/exams/{$id}/targets")->assertStatus(404);
        $this->withHeaders($h)->getJson(self::BASE . "/exams/{$id}/analytics")->assertStatus(404);
        $this->withHeaders($h)->getJson(self::BASE . "/exams/{$id}/results/export")->assertStatus(404);
        $this->withHeaders($h)->getJson(self::BASE . "/exams/{$id}/attempts")->assertStatus(404);

        $this->assertSame('Midterm', DB::table('exams')->where('id', $id)->value('title'));
        $this->assertNull(DB::table('exams')->where('id', $id)->value('deleted_at'));
    }

    #[Test]
    public function the_owner_can_still_reach_their_own_exam(): void
    {
        $h = $this->asStaffA();
        $this->withHeaders($h)->getJson(self::BASE . "/exams/{$this->examId}")->assertStatus(200);
        $this->withHeaders($h)->getJson(self::BASE . "/exams/{$this->examId}/attempts")->assertStatus(200);
    }

    #[Test]
    public function another_instructor_cannot_touch_attempts_of_my_exam(): void
    {
        $attemptId = $this->submittedAttemptFor($this->studentA);
        $h = $this->asStaffB();
        $id = $this->examId;

        $this->withHeaders($h)->getJson(self::BASE . "/exams/{$id}/attempts/{$attemptId}")->assertStatus(404);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/attempts/{$attemptId}/cancel", ['reason' => 'x'])->assertStatus(404);
        $this->withHeaders($h)->putJson(self::BASE . "/exams/{$id}/attempts/{$attemptId}/grades/{$this->examQuestionId}", ['marks_awarded' => 10])->assertStatus(404);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/students/{$this->studentA}/retake")->assertStatus(404);
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$id}/publish-results")->assertStatus(404);

        $this->assertNotSame('cancelled', DB::table('exam_attempts')->where('id', $attemptId)->value('status'));
    }

    #[Test]
    public function an_attempt_id_from_a_different_exam_is_rejected_even_for_the_owner(): void
    {
        // نفس المدرس A عنده امتحان تاني — attempt بتاع الامتحان الأول مينفعش يتفتح من تحت الامتحان التاني.
        [$otherExamId] = $this->makePublishedExam($this->staffA, $this->bankA);
        $attemptId = $this->submittedAttemptFor($this->studentA);

        $this->withHeaders($this->asStaffA())
            ->getJson(self::BASE . "/exams/{$otherExamId}/attempts/{$attemptId}")
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Cross-resource references (a question / pool that isn't mine)
    // ------------------------------------------------------------------

    #[Test]
    public function an_exam_cannot_pull_in_a_question_owned_by_another_instructor(): void
    {
        [$foreignQuestionId] = $this->makeQuestion($this->bankB, $this->staffB);

        $this->withHeaders($this->asStaffA())
            ->postJson(self::BASE . "/exams/{$this->examId}/questions", ['question_id' => $foreignQuestionId])
            ->assertStatus(404);

        $this->assertSame(0, DB::table('exam_questions')->where('exam_id', $this->examId)->where('question_id', $foreignQuestionId)->count());
    }

    #[Test]
    public function a_pool_cannot_be_filled_with_questions_from_another_bank(): void
    {
        $poolId = DB::table('question_pools')->insertGetId([
            'question_bank_id' => $this->bankA, 'created_by_academic_staff_id' => $this->staffA,
            'name' => 'Pool', 'created_at' => now(), 'updated_at' => now(),
        ]);
        [$foreignQuestionId] = $this->makeQuestion($this->bankB, $this->staffB);

        $this->withHeaders($this->asStaffA())
            ->putJson(self::BASE . "/pools/{$poolId}/questions", ['question_ids' => [$foreignQuestionId]])
            ->assertStatus(422);
    }

    #[Test]
    public function another_instructor_cannot_edit_or_delete_my_question_or_bank(): void
    {
        [$questionId] = $this->makeQuestion($this->bankA, $this->staffA);
        $h = $this->asStaffB();

        $this->withHeaders($h)->getJson(self::BASE . "/question-banks/{$this->bankA}")->assertStatus(404);
        $this->withHeaders($h)->patchJson(self::BASE . "/questions/{$questionId}", ['prompt' => 'hacked'])->assertStatus(404);
        $this->withHeaders($h)->deleteJson(self::BASE . "/questions/{$questionId}")->assertStatus(404);
        $this->withHeaders($h)->deleteJson(self::BASE . "/question-banks/{$this->bankA}")->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Student A vs. student B (IDOR on attempts)
    // ------------------------------------------------------------------

    #[Test]
    public function a_student_cannot_read_save_or_submit_another_students_attempt(): void
    {
        $attemptId = $this->startAttemptFor($this->studentA);
        $h = $this->asStudentB();

        $this->withHeaders($h)->getJson(self::BASE . "/attempts/{$attemptId}")->assertStatus(404);
        $this->withHeaders($h)->putJson(self::BASE . "/attempts/{$attemptId}/answers/{$this->examQuestionId}", [
            'selected_option_ids' => [$this->correctOptionId],
        ])->assertStatus(404);
        $this->withHeaders($h)->deleteJson(self::BASE . "/attempts/{$attemptId}/answers/{$this->examQuestionId}")->assertStatus(404);
        $this->withHeaders($h)->postJson(self::BASE . "/attempts/{$attemptId}/submit")->assertStatus(404);
        $this->withHeaders($h)->postJson(self::BASE . "/attempts/{$attemptId}/security-events", ['event_type' => 'tab_switch'])->assertStatus(404);
        $this->withHeaders($h)->getJson(self::BASE . "/attempts/{$attemptId}/result")->assertStatus(404);

        $this->assertSame('in_progress', DB::table('exam_attempts')->where('id', $attemptId)->value('status'));
        $this->assertSame(0, DB::table('exam_answers')->where('exam_attempt_id', $attemptId)->count());
    }

    #[Test]
    public function the_attempt_owner_can_save_an_answer(): void
    {
        $attemptId = $this->startAttemptFor($this->studentA);

        $this->withHeaders($this->asStudentA())
            ->putJson(self::BASE . "/attempts/{$attemptId}/answers/{$this->examQuestionId}", [
                'selected_option_ids' => [$this->correctOptionId],
            ])
            ->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    #[Test]
    public function answer_payload_is_validated(): void
    {
        $attemptId = $this->startAttemptFor($this->studentA);
        $h = $this->asStudentA();
        $url = self::BASE . "/attempts/{$attemptId}/answers/{$this->examQuestionId}";

        $this->withHeaders($h)->putJson($url, ['selected_option_ids' => 'not-an-array'])->assertStatus(422);
        $this->withHeaders($h)->putJson($url, ['selected_option_ids' => ['abc']])->assertStatus(422);
        $this->withHeaders($h)->putJson($url, ['answer_text' => str_repeat('x', 20001)])->assertStatus(422);
    }

    #[Test]
    public function an_answer_cannot_target_a_question_that_is_not_in_the_attempt(): void
    {
        $attemptId = $this->startAttemptFor($this->studentA);
        [$otherExamId, $otherExamQuestionId, $otherCorrect] = $this->makePublishedExam($this->staffA, $this->bankA);

        $this->withHeaders($this->asStudentA())
            ->putJson(self::BASE . "/attempts/{$attemptId}/answers/{$otherExamQuestionId}", ['selected_option_ids' => [$otherCorrect]])
            ->assertStatus(422);
    }

    #[Test]
    public function manual_grade_must_stay_within_zero_and_the_question_marks(): void
    {
        $attemptId = $this->submittedAttemptFor($this->studentA);
        $h = $this->asStaffA();
        $url = self::BASE . "/exams/{$this->examId}/attempts/{$attemptId}/grades/{$this->examQuestionId}";

        $this->withHeaders($h)->putJson($url, ['marks_awarded' => 11])->assertStatus(422);   // أعلى من الدرجة (10)
        $this->withHeaders($h)->putJson($url, ['marks_awarded' => -1])->assertStatus(422);
        $this->withHeaders($h)->putJson($url, [])->assertStatus(422);                          // ناقص marks_awarded
        $this->withHeaders($h)->putJson($url, ['marks_awarded' => 7])->assertStatus(200);
    }

    #[Test]
    public function retake_cannot_be_granted_to_a_student_from_another_university(): void
    {
        [, $outsiderStudentId] = $this->makeStudent($this->uniB);

        $this->withHeaders($this->asStaffA())
            ->postJson(self::BASE . "/exams/{$this->examId}/students/{$outsiderStudentId}/retake")
            ->assertStatus(422);

        $this->assertSame(0, DB::table('exam_student_overrides')->where('exam_id', $this->examId)->where('student_id', $outsiderStudentId)->count());
    }

    #[Test]
    public function retake_extra_attempts_is_bounded(): void
    {
        $this->withHeaders($this->asStaffA())
            ->postJson(self::BASE . "/exams/{$this->examId}/students/{$this->studentB}/retake", ['extra_attempts' => 0])
            ->assertStatus(422);
        $this->withHeaders($this->asStaffA())
            ->postJson(self::BASE . "/exams/{$this->examId}/students/{$this->studentB}/retake", ['extra_attempts' => 9999])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Rate limiting
    // ------------------------------------------------------------------

    #[Test]
    public function exam_attempt_requests_use_their_own_capped_bucket(): void
    {
        config(['security.exam_attempt_rate_limit_per_min' => 5, 'security.rate_limit_per_min' => 1000]);
        \Illuminate\Support\Facades\Cache::flush();

        $attemptId = $this->startAttemptFor($this->studentA);
        $h = $this->asStudentA();
        $url = self::BASE . "/attempts/{$attemptId}/answers/{$this->examQuestionId}";

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($h)->putJson($url, ['selected_option_ids' => [$this->correctOptionId]])->assertStatus(200);
        }
        // السادس فوق السقف.
        $this->withHeaders($h)->putJson($url, ['selected_option_ids' => [$this->correctOptionId]])->assertStatus(429);

        // وباقي الـ API (bucket عام) مش متأثر باستهلاك bucket الامتحان.
        $this->withHeaders($h)->getJson(self::BASE . '/my-exams')->assertStatus(200);
    }
}
