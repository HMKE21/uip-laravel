<?php

namespace Tests\Feature;

use App\Services\UipJwtService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** endpoints كشف التشابه + تظلم الطالب: الصلاحيات (IDOR) + المنطق الأساسي. */
class ExamSimilarityAndAppealsTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/v1/exam-system';

    private int $staffAUser; private int $staffA;
    private int $staffBUser;
    private int $stuAUser; private int $stuA;
    private int $stuBUser; private int $stuB;
    private int $stuCUser; private int $stuC;
    private int $examId; private int $eqId;
    private int $attA; private int $attB; private int $attC;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['JWT_SECRET'] = $_SERVER['JWT_SECRET'] = 'test-secret-similarity-appeals';
        putenv('JWT_SECRET=test-secret-similarity-appeals');

        DB::table('universities')->insert(['id' => 1, 'name' => 'Uni']);
        [$this->staffAUser, $this->staffA] = $this->makeStaff();
        [$this->staffBUser] = $this->makeStaff();
        [$this->stuAUser, $this->stuA] = $this->makeStudent();
        [$this->stuBUser, $this->stuB] = $this->makeStudent();
        [$this->stuCUser, $this->stuC] = $this->makeStudent();

        $bank = DB::table('question_banks')->insertGetId([
            'university_id' => 1, 'created_by_academic_staff_id' => $this->staffA, 'title' => 'B', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $q = DB::table('questions')->insertGetId([
            'question_bank_id' => $bank, 'type' => 'essay', 'prompt' => 'Explain photosynthesis', 'marks' => 10,
            'created_by_academic_staff_id' => $this->staffA, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->examId = DB::table('exams')->insertGetId([
            'university_id' => 1, 'created_by_academic_staff_id' => $this->staffA, 'title' => 'Bio', 'duration_minutes' => 60,
            'max_attempts' => 1, 'status' => 'published', 'total_marks' => 10, 'result_visibility' => 'immediate',
            'show_answer_review' => true, 'show_score_only' => false, 'appeals_enabled' => true, 'appeal_window_days' => 7,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->eqId = DB::table('exam_questions')->insertGetId([
            'exam_id' => $this->examId, 'question_id' => $q, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $copied = 'plants use sunlight water and carbon dioxide to make glucose and oxygen inside the chloroplasts through light reactions and the calvin cycle which stores energy in sugar molecules';
        $this->attA = $this->makeAttempt($this->stuA, $copied);
        $this->attB = $this->makeAttempt($this->stuB, $copied . ' thanks');
        $this->attC = $this->makeAttempt($this->stuC, 'the mitochondria is the powerhouse of the cell and produces atp through oxidative phosphorylation using the electron transport chain across the inner membrane of the organelle in eukaryotes');
    }

    private function makeUser(string $p): int
    {
        return DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'full_name' => $p . ' ' . uniqid(), 'email' => $p . '_' . uniqid() . '@test.local',
            'password_hash' => bcrypt('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeStaff(): array
    {
        $u = $this->makeUser('staff');
        return [$u, DB::table('academic_staff')->insertGetId(['user_id' => $u, 'university_id' => 1, 'created_at' => now(), 'updated_at' => now()])];
    }

    private function makeStudent(): array
    {
        $u = $this->makeUser('student');
        return [$u, DB::table('students')->insertGetId(['user_id' => $u, 'university_id' => 1, 'student_number' => 'S' . uniqid(), 'created_at' => now(), 'updated_at' => now()])];
    }

    private function makeAttempt(int $studentId, string $text): int
    {
        $id = DB::table('exam_attempts')->insertGetId([
            'exam_id' => $this->examId, 'student_id' => $studentId, 'attempt_number' => 1, 'status' => 'graded',
            'submitted_at' => now(), 'score' => 5, 'percentage' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('exam_attempt_questions')->insert(['exam_attempt_id' => $id, 'exam_question_id' => $this->eqId, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('exam_answers')->insert(['exam_attempt_id' => $id, 'exam_question_id' => $this->eqId, 'answer_text' => $text, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function as(int $userId, string $role): array
    {
        return ['Authorization' => 'Bearer ' . UipJwtService::encode(['sub' => $userId, 'role' => $role], 600)];
    }

    // ---------------- similarity ----------------

    #[Test]
    public function similarity_requires_the_exam_owner(): void
    {
        $this->getJson(self::BASE . "/exams/{$this->examId}/similarity")->assertStatus(401);
        $this->withHeaders($this->as($this->stuAUser, 'student'))->getJson(self::BASE . "/exams/{$this->examId}/similarity")->assertStatus(403);
        $this->withHeaders($this->as($this->staffBUser, 'academic_staff'))->getJson(self::BASE . "/exams/{$this->examId}/similarity")->assertStatus(404);
        $this->withHeaders($this->as($this->staffBUser, 'academic_staff'))->postJson(self::BASE . "/exams/{$this->examId}/similarity/analyze")->assertStatus(404);
    }

    #[Test]
    public function analyze_flags_copied_answers_but_not_unrelated_ones_and_keeps_reviews(): void
    {
        $h = $this->as($this->staffAUser, 'academic_staff');
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$this->examId}/similarity/analyze", ['threshold' => 60])
            ->assertOk()->assertJsonPath('data.flags_found', 1);

        $list = $this->withHeaders($h)->getJson(self::BASE . "/exams/{$this->examId}/similarity")->assertOk();
        $this->assertCount(1, $list->json('data.flags'));
        $flagId = $list->json('data.flags.0.id');

        $this->withHeaders($h)->patchJson(self::BASE . "/exams/{$this->examId}/similarity/{$flagId}", ['status' => 'dismissed'])->assertOk();
        $this->withHeaders($h)->postJson(self::BASE . "/exams/{$this->examId}/similarity/analyze")->assertOk();
        $this->withHeaders($h)->getJson(self::BASE . "/exams/{$this->examId}/similarity?status=dismissed")->assertOk()->assertJsonCount(1, 'data.flags');

        // مدرس تاني ميقدرش يراجع إشارة امتحان مش بتاعه
        $this->withHeaders($this->as($this->staffBUser, 'academic_staff'))
            ->patchJson(self::BASE . "/exams/{$this->examId}/similarity/{$flagId}", ['status' => 'confirmed'])->assertStatus(404);
        $this->withHeaders($h)->patchJson(self::BASE . "/exams/{$this->examId}/similarity/{$flagId}", ['status' => 'bogus'])->assertStatus(422);
    }

    // ---------------- appeals ----------------

    #[Test]
    public function a_student_can_only_appeal_their_own_attempt(): void
    {
        $this->withHeaders($this->as($this->stuBUser, 'student'))
            ->postJson(self::BASE . "/attempts/{$this->attA}/appeals", ['reason' => 'I think my answer deserves more marks'])->assertStatus(404);
        $this->withHeaders($this->as($this->stuBUser, 'student'))->getJson(self::BASE . "/attempts/{$this->attA}/appeals")->assertStatus(404);
        $this->withHeaders($this->as($this->staffAUser, 'academic_staff'))->getJson(self::BASE . "/attempts/{$this->attA}/appeals")->assertStatus(403);
    }

    #[Test]
    public function appeal_validation_duplicates_and_disabled_exam(): void
    {
        $h = $this->as($this->stuAUser, 'student');
        $url = self::BASE . "/attempts/{$this->attA}/appeals";
        $this->withHeaders($h)->postJson($url, ['reason' => 'short'])->assertStatus(422);
        $this->withHeaders($h)->postJson($url, ['reason' => 'I think my answer deserves more marks'])->assertStatus(201);
        $this->withHeaders($h)->postJson($url, ['reason' => 'Same overall appeal again please'])->assertStatus(422);

        DB::table('exams')->where('id', $this->examId)->update(['appeals_enabled' => false]);
        $this->withHeaders($this->as($this->stuBUser, 'student'))
            ->postJson(self::BASE . "/attempts/{$this->attB}/appeals", ['reason' => 'I think my answer deserves more marks'])->assertStatus(422);
    }

    #[Test]
    public function only_the_exam_owner_resolves_and_accepting_updates_the_grade(): void
    {
        $student = $this->as($this->stuAUser, 'student');
        $id = $this->withHeaders($student)->postJson(self::BASE . "/attempts/{$this->attA}/appeals",
            ['reason' => 'I think my answer deserves more marks', 'exam_question_id' => $this->eqId])->assertStatus(201)->json('data.id');

        $url = self::BASE . "/exams/{$this->examId}/appeals/{$id}/resolve";
        $this->withHeaders($this->as($this->staffBUser, 'academic_staff'))->postJson($url, ['decision' => 'rejected', 'response' => 'no'])->assertStatus(404);
        $owner = $this->as($this->staffAUser, 'academic_staff');
        $this->withHeaders($owner)->postJson($url, ['decision' => 'rejected'])->assertStatus(422); // الرفض لازم بسبب

        $this->withHeaders($owner)->postJson($url, ['decision' => 'accepted', 'response' => 'Regraded', 'new_marks' => 8])->assertOk();
        $this->assertEquals(8.0, (float) DB::table('exam_grades')->where('exam_attempt_id', $this->attA)->value('marks_awarded'));
        $this->assertDatabaseHas('exam_grade_appeals', ['id' => $id, 'status' => 'accepted']);
        $this->withHeaders($owner)->postJson($url, ['decision' => 'rejected', 'response' => 'late'])->assertStatus(422); // اتحسم قبل كده
    }
}
