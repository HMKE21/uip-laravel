<?php

namespace Tests\Feature;

use App\Models\ExamAttempt;
use App\Services\ExamAttemptService;
use App\Services\ExamSecurityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests للـ Secure Exam Mode (ExamSecurityService):
 *
 *  - أحداث "مخالفة" (VIOLATION_EVENTS) بس هي اللي بتزوّد violations_count؛
 *    باقي أحداث المتصفح إعلامية بحت.
 *  - event_type لازم يكون من CLIENT_EVENTS — مينفعش حد "يقلّد" حدث نظامي
 *    (زي auto_submitted) عبر recordClientEvent().
 *  - تسجيل حدث على محاولة خلصت أصلاً يترفض.
 *  - threshold_exceeded=true لما violations_count يوصل max_violations —
 *    وده القرار اللي الـ controller بيبني عليه نداء
 *    ExamAttemptService::autoSubmitDueToViolations() (مختبر هنا integration
 *    مش mock، عشان نتأكد المسارين شغالين مع بعض صح).
 *  - IMPORTANT: هذا اختبار "سلوك برمجي" بس — مش بيغطي (ولا يقدر يغطي)
 *    التحذير الأهم من التحليل الأصلي: النظام ده browser-level رصد سلوك،
 *    مش lockdown حقيقي. ده قرار منتج/بنية مش حاجة test يقدر يتحقق منها.
 */
class ExamSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private ExamSecurityService $security;
    private ExamAttemptService $attemptService;
    private int $universityId = 1;
    private int $staffId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->security = app(ExamSecurityService::class);
        $this->attemptService = app(ExamAttemptService::class);

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

    /** امتحان منشور + طالب مؤهل + محاولة شغالة، بدون أي أسئلة (مش محتاجينها لاختبارات الأمان). */
    private function startAttemptFor(?int $maxViolations = null): ExamAttempt
    {
        $examId = DB::table('exams')->insertGetId([
            'university_id' => $this->universityId, 'created_by_academic_staff_id' => $this->staffId,
            'title' => 'Exam', 'duration_minutes' => 60, 'max_attempts' => 1,
            'max_violations' => $maxViolations, 'status' => 'published', 'total_marks' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('exam_targets')->insert(['exam_id' => $examId, 'created_at' => now(), 'updated_at' => now()]);
        $studentId = $this->makeStudent();

        // Model::create() ما بيرجعش الأعمدة اللي عندها DB default (زي
        // violations_count=0) في نفس الـ PHP object — في الإنتاج الحقيقي
        // ده مش مشكلة (كل request بيجيب المحاولة fresh من الداتابيز عبر
        // route model binding)، بس هنا لازم نعمل ->fresh() يدويًا عشان
        // الاختبار يحاكي نفس سلوك request منفصل صح.
        return $this->attemptService->startAttempt($examId, $studentId, $this->universityId)['attempt']->fresh();
    }

    #[Test]
    public function a_non_violation_event_is_logged_but_does_not_count_as_a_violation(): void
    {
        $attempt = $this->startAttemptFor();

        $result = $this->security->recordClientEvent($attempt, null, 'fullscreen_entered');

        $this->assertFalse($result['event']->is_violation);
        $this->assertSame(0, $result['violations_count']);
        $this->assertSame(0, $attempt->fresh()->violations_count);
    }

    #[Test]
    public function a_violation_event_is_logged_and_increments_the_attempts_violation_count(): void
    {
        $attempt = $this->startAttemptFor();

        $result = $this->security->recordClientEvent($attempt, ['tab' => 'gmail.com'], 'tab_switch');

        $this->assertTrue($result['event']->is_violation);
        $this->assertSame(1, $result['violations_count']);
        $this->assertSame(1, $attempt->fresh()->violations_count);
    }

    #[Test]
    public function an_unknown_or_system_only_event_type_cannot_be_reported_by_the_client(): void
    {
        $attempt = $this->startAttemptFor();

        // 'auto_submitted' حدث نظامي بس (عبر logSystemEvent) — مينفعش حد
        // يبعته كـ client event ويحاول يقلّد إنه حصل auto-submit فعلي.
        $this->expectException(\InvalidArgumentException::class);
        $this->security->recordClientEvent($attempt, null, 'auto_submitted');
    }

    #[Test]
    public function events_cannot_be_recorded_against_an_attempt_that_already_finished(): void
    {
        $attempt = $this->startAttemptFor();
        $this->attemptService->submitAttempt($attempt);

        $this->expectException(\InvalidArgumentException::class);
        $this->security->recordClientEvent($attempt->fresh(), null, 'tab_switch');
    }

    #[Test]
    public function threshold_exceeded_is_reported_once_violations_reach_the_exams_limit(): void
    {
        $attempt = $this->startAttemptFor(maxViolations: 2);

        $first = $this->security->recordClientEvent($attempt, null, 'tab_switch');
        $this->assertFalse($first['threshold_exceeded']);

        $second = $this->security->recordClientEvent($attempt->fresh(), null, 'copy_attempt');
        $this->assertTrue($second['threshold_exceeded']);
        $this->assertSame(2, $second['violations_count']);
        $this->assertSame(2, $second['max_violations']);
    }

    #[Test]
    public function exceeding_the_violation_threshold_actually_auto_submits_the_attempt(): void
    {
        // ده الجزء اللي الـ controller بيعمله: يقرا threshold_exceeded من
        // recordClientEvent() وبعدين ينادي autoSubmitDueToViolations() —
        // بنتأكد المسارين شغالين صح مع بعض (integration مش mock).
        $attempt = $this->startAttemptFor(maxViolations: 1);

        $result = $this->security->recordClientEvent($attempt, null, 'tab_switch');
        $this->assertTrue($result['threshold_exceeded']);

        $final = $this->attemptService->autoSubmitDueToViolations($attempt->fresh());

        // ملحوظة: exam.isFullyGraded() بترجع false دايمًا لو عدد الأسئلة =
        // صفر (لا يوجد "امتحان" فعلي هنا، بس محاولة أمان مجردة) — فالمحاولة
        // بتقف عند 'grading' مش 'graded'. اللي بيهمنا هنا هو auto_submitted
        // نفسها والـ event، مش status النهائي (مغطّى فعلًا في
        // ExamAttemptLifecycleTest لامتحان بأسئلة حقيقية).
        $this->assertSame('grading', $final->status);
        $this->assertTrue((bool) $final->auto_submitted);

        $event = DB::table('exam_security_events')
            ->where('exam_attempt_id', $attempt->id)->where('event_type', 'auto_submitted')->first();
        $this->assertNotNull($event);
        $metadata = json_decode($event->metadata, true);
        $this->assertSame('max_violations_exceeded', $metadata['reason']);
        $this->assertSame(1, $metadata['violations_count']);
    }

    #[Test]
    public function an_exam_with_no_violation_limit_never_reports_threshold_exceeded(): void
    {
        $attempt = $this->startAttemptFor(maxViolations: null);

        // كتّرنا المخالفات عمدًا — من غير max_violations محدد، مفيش سقف يتخطاه.
        $last = null;
        foreach (range(1, 5) as $_) {
            $last = $this->security->recordClientEvent($attempt->fresh(), null, 'tab_switch');
        }

        $this->assertSame(5, $last['violations_count']);
        $this->assertNull($last['max_violations']);
        $this->assertFalse($last['threshold_exceeded']);
    }

    #[Test]
    public function the_instructor_timeline_returns_events_in_chronological_order(): void
    {
        $attempt = $this->startAttemptFor();
        // exam_started system event already logged by startAttempt() above.
        $this->security->recordClientEvent($attempt, null, 'fullscreen_entered');
        $this->security->recordClientEvent($attempt, null, 'tab_switch');

        $timeline = $this->security->timelineForAttempt($attempt);
        $types = array_column($timeline, 'event_type');

        $this->assertSame(['exam_started', 'fullscreen_entered', 'tab_switch'], $types);
        $this->assertSame([false, false, true], array_column($timeline, 'is_violation'));
    }
}
