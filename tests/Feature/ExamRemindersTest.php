<?php

namespace Tests\Feature;

use App\Services\ExamReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** تذكيرات الامتحانات التلقائية: المراحل، الـ dedup، استبعاد اللي خلّص، وإعادة الجدولة. */
class ExamRemindersTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $now;
    private int $studentUser;
    private int $studentId;
    private int $staffId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-11-01 12:00:00');
        DB::table('universities')->insert(['id' => 1, 'name' => 'Uni']);
        $staffUser = $this->makeUser();
        $this->staffId = DB::table('academic_staff')->insertGetId(['user_id' => $staffUser, 'university_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->studentUser = $this->makeUser();
        $this->studentId = DB::table('students')->insertGetId(['user_id' => $this->studentUser, 'university_id' => 1, 'student_number' => 'S' . uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeUser(): int
    {
        return DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'full_name' => 'u ' . uniqid(), 'email' => uniqid() . '@test.local',
            'password_hash' => bcrypt('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeExam(string $status, ?Carbon $start, ?Carbon $end, int $maxAttempts = 1): int
    {
        $id = DB::table('exams')->insertGetId([
            'university_id' => 1, 'created_by_academic_staff_id' => $this->staffId, 'title' => 'Exam', 'duration_minutes' => 60,
            'max_attempts' => $maxAttempts, 'status' => $status, 'total_marks' => 10, 'start_at' => $start, 'end_at' => $end,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('exam_targets')->insert(['exam_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function sweep(): array
    {
        return app(ExamReminderService::class)->sweep($this->now);
    }

    private function count(string $type): int
    {
        return DB::table('notifications')->where('user_id', $this->studentUser)->where('type', $type)->count();
    }

    #[Test]
    public function closing_reminder_goes_once_and_reaches_scheduled_exams_that_already_started(): void
    {
        // status 'scheduled' بعد ما الامتحان بدأ فعلًا — الحالة اللي النسخة القديمة كانت بتفوّتها.
        $this->makeExam('scheduled', $this->now->copy()->subDay(), $this->now->copy()->addMinutes(30));

        $this->assertSame(1, $this->sweep()['closes']);
        $this->assertSame(0, $this->sweep()['closes']); // dedup
        $this->assertSame(1, $this->count('exam_deadline_approaching'));
        $this->assertStringStartsWith('/student/my-exams/', DB::table('notifications')->where('user_id', $this->studentUser)->value('link_url'));
    }

    #[Test]
    public function opening_reminder_and_stage_progression(): void
    {
        $examId = $this->makeExam('scheduled', $this->now->copy()->addHours(20), $this->now->copy()->addHours(30));
        $this->assertSame(1, $this->sweep()['opens']);          // مرحلة 24h

        $this->now = $this->now->copy()->addHours(19)->addMinutes(30); // فاضل 30 دقيقة
        $this->assertSame(1, $this->sweep()['opens']);          // مرحلة 1h (نوع مختلف)
        $this->assertSame(0, $this->sweep()['opens']);
        $this->assertSame(2, DB::table('exam_reminders_sent')->where('exam_id', $examId)->count());
    }

    #[Test]
    public function an_exam_far_away_or_already_open_does_not_get_an_opening_reminder(): void
    {
        $this->makeExam('scheduled', $this->now->copy()->addDays(5), null);
        $this->makeExam('published', $this->now->copy()->subHour(), $this->now->copy()->addDays(5));
        $this->assertSame(['opens' => 0, 'closes' => 0], $this->sweep());
    }

    #[Test]
    public function students_who_used_all_attempts_or_are_mid_attempt_get_no_closing_reminder(): void
    {
        $examId = $this->makeExam('published', $this->now->copy()->subDay(), $this->now->copy()->addMinutes(30), 1);
        DB::table('exam_attempts')->insert([
            'exam_id' => $examId, 'student_id' => $this->studentId, 'attempt_number' => 1, 'status' => 'submitted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(0, $this->sweep()['closes']);

        // محاولة إضافية من المدرس → يرجع يستاهل تذكير
        DB::table('exam_student_overrides')->insert(['exam_id' => $examId, 'student_id' => $this->studentId, 'extra_attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, $this->sweep()['closes']);
    }

    #[Test]
    public function rescheduling_an_exam_allows_a_fresh_reminder(): void
    {
        $examId = $this->makeExam('scheduled', $this->now->copy()->addMinutes(40), $this->now->copy()->addHours(3));
        $this->assertSame(1, $this->sweep()['opens']);

        DB::table('exams')->where('id', $examId)->update(['start_at' => $this->now->copy()->addMinutes(50)]);
        $this->assertSame(1, $this->sweep()['opens']);
    }
}
