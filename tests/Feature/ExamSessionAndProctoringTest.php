<?php

namespace Tests\Feature;

use App\Models\ExamAttempt;
use App\Services\UipJwtService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * جلسة واحدة للطالب + مراقبة الكاميرا/تحقق الهوية — على مستوى الـ HTTP endpoints (JWT حقيقي عبر uip.auth).
 */
class ExamSessionAndProctoringTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/v1/exam-system';

    private int $uni = 1;
    private int $staffA, $staffAUser, $staffB, $staffBUser;
    private int $studentA, $studentAUser, $studentB, $studentBUser;
    private int $examId, $examQuestionId;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['JWT_SECRET'] = $_SERVER['JWT_SECRET'] = 'test-secret-for-session-tests';
        putenv('JWT_SECRET=test-secret-for-session-tests');
        Storage::fake('local');

        DB::table('universities')->insert([['id' => $this->uni, 'name' => 'Uni A']]);
        [$this->staffAUser, $this->staffA] = $this->makeStaff();
        [$this->staffBUser, $this->staffB] = $this->makeStaff();
        [$this->studentAUser, $this->studentA] = $this->makeStudent();
        [$this->studentBUser, $this->studentB] = $this->makeStudent();

        $bank = DB::table('question_banks')->insertGetId([
            'university_id' => $this->uni, 'created_by_academic_staff_id' => $this->staffA,
            'title' => 'Bank', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->examId = DB::table('exams')->insertGetId([
            'university_id' => $this->uni, 'created_by_academic_staff_id' => $this->staffA,
            'title' => 'Midterm', 'duration_minutes' => 60, 'max_attempts' => 2,
            'status' => 'published', 'total_marks' => 10, 'max_violations' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('exam_targets')->insert(['exam_id' => $this->examId, 'created_at' => now(), 'updated_at' => now()]);
        $q = DB::table('questions')->insertGetId([
            'question_bank_id' => $bank, 'type' => 'mcq', 'prompt' => 'Q?', 'marks' => 10,
            'created_by_academic_staff_id' => $this->staffA, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('question_options')->insert([
            ['question_id' => $q, 'option_text' => 'Right', 'is_correct' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['question_id' => $q, 'option_text' => 'Wrong', 'is_correct' => false, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->examQuestionId = DB::table('exam_questions')->insertGetId([
            'exam_id' => $this->examId, 'question_id' => $q, 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    private function makeUser(string $prefix): int
    {
        return DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'full_name' => $prefix . ' ' . uniqid(),
            'email' => $prefix . '_' . uniqid() . '@test.local', 'password_hash' => bcrypt('secret'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeStaff(): array
    {
        $u = $this->makeUser('staff');
        return [$u, DB::table('academic_staff')->insertGetId([
            'user_id' => $u, 'university_id' => $this->uni, 'created_at' => now(), 'updated_at' => now(),
        ])];
    }

    private function makeStudent(): array
    {
        $u = $this->makeUser('student');
        return [$u, DB::table('students')->insertGetId([
            'user_id' => $u, 'university_id' => $this->uni, 'student_number' => 'S' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ])];
    }

    private function jwt(int $userId, string $role): array
    {
        return ['Authorization' => 'Bearer ' . UipJwtService::encode(['sub' => $userId, 'role' => $role], 600)];
    }

    private function student(array $extra = []): array
    {
        return $this->jwt($this->studentAUser, 'student') + $extra;
    }

    private function staffA(): array { return $this->jwt($this->staffAUser, 'academic_staff'); }
    private function staffB(): array { return $this->jwt($this->staffBUser, 'academic_staff'); }

    private function dev(string $id, ?string $token = null): array
    {
        return ['X-Device-Id' => $id] + ($token ? ['X-Exam-Session' => $token] : []);
    }

    private function updateExam(array $data): void
    {
        DB::table('exams')->where('id', $this->examId)->update($data);
    }

    private function startUrl(): string
    {
        return self::BASE . "/my-exams/{$this->examId}/attempts";
    }

    private function img(string $name = 'p.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 200, 200);
    }

    /** @return array{0:int,1:string} [attemptId, token] */
    private function startOnDevice1(): array
    {
        $res = $this->withHeaders($this->student($this->dev('dev-1')))->postJson($this->startUrl());
        $res->assertStatus(201);
        return [(int) $res->json('data.id'), (string) $res->json('data.session.token')];
    }

    // ------------------------------------------------------------------ single session

    #[Test]
    public function starting_an_attempt_issues_a_session_token_and_stores_only_its_hash(): void
    {
        [$attemptId, $token] = $this->startOnDevice1();

        $this->assertSame(48, strlen($token));
        $row = DB::table('exam_attempts')->where('id', $attemptId)->first();
        $this->assertSame(hash('sha256', $token), $row->session_token_hash);
        $this->assertSame(1, (int) $row->session_claims_count);
        $this->assertSame('dev-1', $row->session_device_id);
        $this->assertNotNull($row->session_ip);
        $this->assertSame(1, DB::table('exam_attempt_sessions')->where('exam_attempt_id', $attemptId)->where('event', 'claim')->count());
    }

    #[Test]
    public function requests_without_or_with_the_wrong_token_are_rejected_with_409(): void
    {
        [$attemptId, $token] = $this->startOnDevice1();

        $this->withHeaders($this->student($this->dev('dev-1')))
            ->getJson(self::BASE . "/attempts/{$attemptId}")
            ->assertStatus(409)->assertJsonPath('data.code', 'session_required');

        $this->withHeaders($this->student($this->dev('dev-1', 'not-the-token')))
            ->putJson(self::BASE . "/attempts/{$attemptId}/answers/{$this->examQuestionId}", ['answer_text' => 'x'])
            ->assertStatus(409)->assertJsonPath('data.code', 'session_replaced');

        $this->withHeaders($this->student($this->dev('dev-1', $token)))
            ->getJson(self::BASE . "/attempts/{$attemptId}")->assertOk();
        $this->withHeaders($this->student($this->dev('dev-1', $token)))
            ->postJson(self::BASE . "/attempts/{$attemptId}/heartbeat")
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
    }

    #[Test]
    public function a_second_device_is_blocked_while_the_first_session_is_live_and_sees_no_questions(): void
    {
        [$attemptId] = $this->startOnDevice1();

        $res = $this->withHeaders($this->student($this->dev('dev-2')))->postJson($this->startUrl());
        $res->assertStatus(409)
            ->assertJsonPath('data.code', 'session_conflict')
            ->assertJsonPath('data.can_takeover', true);
        $this->assertArrayNotHasKey('questions', (array) $res->json('data'));

        $this->assertSame(1, DB::table('exam_attempt_sessions')->where('exam_attempt_id', $attemptId)->where('event', 'blocked')->count());
        $this->assertSame(1, DB::table('exam_security_events')->where('exam_attempt_id', $attemptId)->where('event_type', 'session_conflict')->count());

        $this->withHeaders($this->student($this->dev('dev-2')))->postJson($this->startUrl())->assertStatus(409);
        $this->assertSame(1, DB::table('exam_attempt_sessions')->where('exam_attempt_id', $attemptId)->where('event', 'blocked')->count());
    }

    #[Test]
    public function the_same_device_can_resume_and_the_previous_tab_token_is_replaced(): void
    {
        [$attemptId, $oldToken] = $this->startOnDevice1();

        $res = $this->withHeaders($this->student($this->dev('dev-1')))->postJson($this->startUrl());
        $res->assertOk();
        $newToken = $res->json('data.session.token');
        $this->assertNotSame($oldToken, $newToken);

        $this->withHeaders($this->student($this->dev('dev-1', $oldToken)))
            ->getJson(self::BASE . "/attempts/{$attemptId}")
            ->assertStatus(409)->assertJsonPath('data.code', 'session_replaced');
        $this->assertSame(0, (int) DB::table('exam_attempts')->where('id', $attemptId)->value('violations_count'));
    }

    #[Test]
    public function a_stale_session_can_be_claimed_by_another_device_without_a_violation(): void
    {
        [$attemptId] = $this->startOnDevice1();
        DB::table('exam_attempts')->where('id', $attemptId)->update(['session_last_seen_at' => now()->subMinutes(5)]);

        $this->withHeaders($this->student($this->dev('dev-2')))->postJson($this->startUrl())->assertOk();
        $this->assertSame(0, (int) DB::table('exam_attempts')->where('id', $attemptId)->value('violations_count'));
        $this->assertSame(1, DB::table('exam_attempt_sessions')->where('exam_attempt_id', $attemptId)->where('event', 'resume')->count());
    }

    #[Test]
    public function an_explicit_takeover_locks_the_old_device_out_and_counts_as_a_violation(): void
    {
        [$attemptId, $oldToken] = $this->startOnDevice1();

        $res = $this->withHeaders($this->student($this->dev('dev-2')))->postJson($this->startUrl(), ['takeover' => true]);
        $res->assertOk()->assertJsonPath('data.session.takeover', true);
        $newToken = $res->json('data.session.token');

        $this->withHeaders($this->student($this->dev('dev-1', $oldToken)))
            ->postJson(self::BASE . "/attempts/{$attemptId}/heartbeat")
            ->assertStatus(409)->assertJsonPath('data.code', 'session_replaced');
        $this->withHeaders($this->student($this->dev('dev-2', $newToken)))
            ->postJson(self::BASE . "/attempts/{$attemptId}/heartbeat")->assertOk();

        $this->assertSame(1, (int) DB::table('exam_attempts')->where('id', $attemptId)->value('violations_count'));
        $this->assertSame(1, DB::table('exam_security_events')
            ->where('exam_attempt_id', $attemptId)->where('event_type', 'session_takeover')->where('is_violation', true)->count());
    }

    #[Test]
    public function repeated_takeovers_auto_submit_once_max_violations_is_reached(): void
    {
        $this->updateExam(['max_violations' => 2]);
        [$attemptId] = $this->startOnDevice1();

        $this->withHeaders($this->student($this->dev('dev-2')))->postJson($this->startUrl(), ['takeover' => true])->assertOk();
        $this->withHeaders($this->student($this->dev('dev-1')))->postJson($this->startUrl(), ['takeover' => true])->assertOk();

        $this->assertContains(DB::table('exam_attempts')->where('id', $attemptId)->value('status'), ['auto_submitted', 'graded', 'grading']);
    }

    #[Test]
    public function the_session_guard_can_be_disabled_per_exam(): void
    {
        $this->updateExam(['single_session_enabled' => false]);

        $res = $this->withHeaders($this->student($this->dev('dev-1')))->postJson($this->startUrl());
        $res->assertStatus(201);
        $this->assertNull($res->json('data.session.token'));
        $attemptId = (int) $res->json('data.id');

        $this->withHeaders($this->student($this->dev('dev-2')))->getJson(self::BASE . "/attempts/{$attemptId}")->assertOk();
    }

    #[Test]
    public function a_finished_attempt_is_readable_without_a_session_token(): void
    {
        [$attemptId, $token] = $this->startOnDevice1();
        $this->withHeaders($this->student($this->dev('dev-1', $token)))->postJson(self::BASE . "/attempts/{$attemptId}/submit")->assertOk();

        $this->withHeaders($this->student($this->dev('dev-9')))->getJson(self::BASE . "/attempts/{$attemptId}")->assertOk();
    }

    #[Test]
    public function another_student_still_cannot_touch_the_attempt(): void
    {
        [$attemptId, $token] = $this->startOnDevice1();
        $h = $this->jwt($this->studentBUser, 'student') + $this->dev('dev-1', $token);

        $this->withHeaders($h)->getJson(self::BASE . "/attempts/{$attemptId}")->assertStatus(404);
        $this->withHeaders($h)->postJson(self::BASE . "/attempts/{$attemptId}/snapshots", ['photo' => $this->img()])->assertStatus(404);
    }

    // ------------------------------------------------------------------ proctoring

    #[Test]
    public function a_required_camera_exam_rejects_a_start_without_a_photo_and_creates_no_attempt(): void
    {
        $this->updateExam(['proctoring_mode' => 'required']);

        $this->withHeaders($this->student($this->dev('dev-1')))->postJson($this->startUrl())
            ->assertStatus(422)->assertJsonPath('data.code', 'start_photo_required');
        $this->assertSame(0, DB::table('exam_attempts')->where('exam_id', $this->examId)->count());
    }

    #[Test]
    public function a_required_camera_exam_starts_with_a_valid_photo_and_rejects_non_images(): void
    {
        $this->updateExam(['proctoring_mode' => 'required']);
        $h = $this->student($this->dev('dev-1') + ['Accept' => 'application/json']);

        $this->withHeaders($h)->post($this->startUrl(), ['start_photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertStatus(422);
        $this->assertSame(0, DB::table('exam_attempts')->count());

        $res = $this->withHeaders($h)->post($this->startUrl(), ['start_photo' => $this->img(), 'photo_flags' => json_encode(['too_dark', 'evil'])]);
        $res->assertStatus(201)->assertJsonPath('data.proctoring.mode', 'required');

        $attemptId = (int) $res->json('data.id');
        $snap = DB::table('exam_proctoring_snapshots')->where('exam_attempt_id', $attemptId)->first();
        $this->assertSame('periodic', $snap->kind);
        $this->assertSame(['too_dark'], json_decode($snap->flags, true));
        Storage::disk('local')->assertExists($snap->path);
        $this->assertSame(1, (int) DB::table('exam_attempts')->where('id', $attemptId)->value('proctoring_flags_count'));
    }

    #[Test]
    public function identity_check_requires_a_photo_marks_the_attempt_pending_and_the_owner_can_review_it(): void
    {
        $this->updateExam(['identity_check_required' => true, 'proctoring_mode' => 'off']);
        $h = $this->student($this->dev('dev-1') + ['Accept' => 'application/json']);

        $res = $this->withHeaders($h)->post($this->startUrl(), ['start_photo' => $this->img('me.jpg'), 'id_card' => $this->img('id.jpg')]);
        $res->assertStatus(201)->assertJsonPath('data.identity_status', 'pending');
        $attemptId = (int) $res->json('data.id');

        $this->assertSame(['identity', 'id_card'], DB::table('exam_proctoring_snapshots')
            ->where('exam_attempt_id', $attemptId)->orderBy('id')->pluck('kind')->all());

        $base = self::BASE . "/exams/{$this->examId}/attempts/{$attemptId}";

        $this->withHeaders($this->staffA())->postJson("$base/identity-review", ['decision' => 'reject'])->assertStatus(422);
        $this->withHeaders($this->staffA())->postJson("$base/identity-review", ['decision' => 'reject', 'note' => 'blurry'])
            ->assertOk()->assertJsonPath('data.identity_status', 'rejected');
        $this->withHeaders($this->staffA())->postJson("$base/identity-review", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.identity_status', 'approved');

        $types = DB::table('exam_security_events')->where('exam_attempt_id', $attemptId)->pluck('event_type')->all();
        foreach (['identity_submitted', 'identity_rejected', 'identity_approved'] as $t) {
            $this->assertContains($t, $types);
        }
    }

    #[Test]
    public function integrity_data_and_snapshot_images_are_only_visible_to_the_exam_owner(): void
    {
        $this->updateExam(['proctoring_mode' => 'required']);
        $h = $this->student($this->dev('dev-1') + ['Accept' => 'application/json']);
        $attemptId = (int) $this->withHeaders($h)->post($this->startUrl(), ['start_photo' => $this->img()])->json('data.id');
        $snapId = (int) DB::table('exam_proctoring_snapshots')->where('exam_attempt_id', $attemptId)->value('id');
        $base = self::BASE . "/exams/{$this->examId}/attempts/{$attemptId}";

        $res = $this->withHeaders($this->staffA())->getJson("$base/integrity");
        $res->assertOk()->assertJsonPath('data.proctoring.snapshots_count', 1);
        $this->assertStringNotContainsString('exam-proctoring', $res->getContent(), 'storage path must never leak');
        $this->assertStringNotContainsString('session_token_hash', $res->getContent());

        $this->withHeaders($this->staffA())->get("$base/snapshots/{$snapId}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->withHeaders($this->staffB())->getJson("$base/integrity")->assertStatus(404);
        $this->withHeaders($this->staffB())->get("$base/snapshots/{$snapId}")->assertStatus(404);
        $this->withHeaders($this->staffB())->postJson("$base/identity-review", ['decision' => 'approve'])->assertStatus(404);
        $this->withHeaders($this->student())->getJson("$base/integrity")->assertStatus(403);
        $this->getJson("$base/integrity")->assertStatus(401);
    }

    #[Test]
    public function periodic_snapshots_are_throttled_flagged_and_gaps_are_logged(): void
    {
        $this->updateExam(['proctoring_mode' => 'optional', 'snapshot_interval_seconds' => 60]);
        [$attemptId, $token] = $this->startOnDevice1();
        $h = $this->student($this->dev('dev-1', $token) + ['Accept' => 'application/json']);
        $url = self::BASE . "/attempts/{$attemptId}/snapshots";

        $this->withHeaders($h)->post($url, ['photo' => $this->img(), 'flags' => json_encode(['no_face'])])
            ->assertOk()->assertJsonPath('data.stored', true)->assertJsonPath('data.flagged', true);

        $this->withHeaders($h)->post($url, ['photo' => $this->img()])
            ->assertOk()->assertJsonPath('data.stored', false)->assertJsonPath('data.throttled', true);
        $this->assertSame(1, DB::table('exam_proctoring_snapshots')->where('exam_attempt_id', $attemptId)->count());

        DB::table('exam_proctoring_snapshots')->where('exam_attempt_id', $attemptId)->update(['captured_at' => now()->subMinutes(30)]);
        $this->withHeaders($h)->post($url, ['photo' => $this->img()])->assertOk()->assertJsonPath('data.stored', true);
        $this->assertSame(1, DB::table('exam_security_events')->where('exam_attempt_id', $attemptId)->where('event_type', 'proctoring_gap')->count());
        $this->assertSame(1, (int) DB::table('exam_attempts')->where('id', $attemptId)->value('proctoring_flags_count'));
    }

    #[Test]
    public function snapshot_upload_is_refused_when_the_camera_is_off_or_the_file_is_not_an_image(): void
    {
        [$attemptId, $token] = $this->startOnDevice1();
        $h = $this->student($this->dev('dev-1', $token) + ['Accept' => 'application/json']);
        $url = self::BASE . "/attempts/{$attemptId}/snapshots";

        $this->withHeaders($h)->post($url, ['photo' => $this->img()])->assertStatus(422);

        $this->updateExam(['proctoring_mode' => 'optional']);
        $this->withHeaders($h)->post($url, ['photo' => UploadedFile::fake()->create('x.txt', 5, 'text/plain')])->assertStatus(422);
        $this->withHeaders($h)->post($url, [])->assertStatus(422);
    }

    #[Test]
    public function a_waived_student_starts_without_a_photo_and_only_the_owner_can_waive(): void
    {
        $this->updateExam(['proctoring_mode' => 'required']);
        $url = self::BASE . "/exams/{$this->examId}/students/{$this->studentA}/proctoring-waiver";

        $this->withHeaders($this->staffB())->putJson($url, ['waived' => true])->assertStatus(404);
        $this->withHeaders($this->student())->putJson($url, ['waived' => true])->assertStatus(403);
        $this->withHeaders($this->staffA())->putJson($url, [])->assertStatus(422);

        $this->withHeaders($this->staffA())->putJson($url, ['waived' => true, 'reason' => 'webcam broken'])
            ->assertOk()->assertJsonPath('data.proctoring_waived', true);
        $this->assertSame(0, (int) DB::table('exam_student_overrides')->where('exam_id', $this->examId)->where('student_id', $this->studentA)->value('extra_attempts'));

        $res = $this->withHeaders($this->student($this->dev('dev-1')))->postJson($this->startUrl());
        $res->assertStatus(201)->assertJsonPath('data.proctoring.mode', 'off')->assertJsonPath('data.proctoring.waived', true);
    }

    #[Test]
    public function camera_denied_or_stopped_is_a_violation_only_when_the_camera_is_required(): void
    {
        $this->updateExam(['proctoring_mode' => 'optional']);
        [$attemptId, $token] = $this->startOnDevice1();
        $h = $this->student($this->dev('dev-1', $token));
        $this->withHeaders($h)->postJson(self::BASE . "/attempts/{$attemptId}/security-events", ['event_type' => 'camera_stopped'])
            ->assertOk()->assertJsonPath('data.is_violation', false);
        $this->assertSame(0, (int) ExamAttempt::find($attemptId)->violations_count);

        $this->updateExam(['proctoring_mode' => 'required']);
        $this->withHeaders($h)->postJson(self::BASE . "/attempts/{$attemptId}/security-events", ['event_type' => 'camera_stopped'])
            ->assertOk()->assertJsonPath('data.is_violation', true)->assertJsonPath('data.violations_count', 1);
    }

    #[Test]
    public function system_only_events_cannot_be_forged_by_the_client(): void
    {
        [$attemptId, $token] = $this->startOnDevice1();
        $h = $this->student($this->dev('dev-1', $token));

        foreach (['session_takeover', 'identity_approved', 'proctoring_flag'] as $type) {
            $this->withHeaders($h)->postJson(self::BASE . "/attempts/{$attemptId}/security-events", ['event_type' => $type])
                ->assertStatus(422);
        }
        $this->assertSame(0, (int) ExamAttempt::find($attemptId)->violations_count);
    }

    #[Test]
    public function exam_settings_are_validated_normalised_and_identity_forces_required(): void
    {
        $url = self::BASE . "/exams/{$this->examId}";

        $this->withHeaders($this->staffA())->patchJson($url, ['proctoring_mode' => 'sometimes'])->assertStatus(422);
        $this->withHeaders($this->staffA())->patchJson($url, ['snapshot_interval_seconds' => 5])->assertStatus(422);

        $this->withHeaders($this->staffA())->patchJson($url, ['proctoring_mode' => 'optional', 'snapshot_interval_seconds' => 30])->assertOk();
        $this->assertSame('optional', DB::table('exams')->where('id', $this->examId)->value('proctoring_mode'));

        $this->withHeaders($this->staffA())->patchJson($url, ['identity_check_required' => true])->assertOk();
        $this->assertSame('required', DB::table('exams')->where('id', $this->examId)->value('proctoring_mode'));

        $this->withHeaders($this->staffB())->patchJson($url, ['proctoring_mode' => 'off'])->assertStatus(404);
    }
}
