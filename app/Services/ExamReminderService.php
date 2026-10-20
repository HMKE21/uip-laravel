<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamRepository;
use App\Repositories\ExamTargetRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * تذكيرات الامتحانات التلقائية: قبل الفتح وقبل الإقفال، على مرحلتين (24 ساعة ثم ساعة).
 *
 * - كل exam/موعد بياخد المرحلة الأقرب بس: لو فاضل أقل من ساعة يبعت تذكير "ساعة" فقط (مش الاتنين مع بعض
 *   حتى لو الـ sweep اتأخر أو الامتحان اتنشر متأخر).
 * - تذكير الإقفال بيتخطى الطالب اللي خلّص محاولاته (نفس شرط assertAccessible()، شاملًا extra_attempts)
 *   أو اللي محاولته شغالة دلوقتي — مفيش داعي يتذكر بحاجة هو فيها أو خلصها.
 * - Dedup عبر exam_reminders_sent (unique + insertOrIgnore، آمن حتى لو sweepين اشتغلوا مع بعض).
 *   target_at جزء من المفتاح، فتغيير موعد الامتحان بعد التذكير بيسمح بتذكير جديد بالموعد الجديد.
 * - الإشعار بيودّي لصفحة الامتحان الفعلية (/student/my-exams/{id}).
 */
class ExamReminderService
{
    /** مراحل التذكير بالدقايق، من الأقرب للأبعد. */
    public const STAGES = ['1h' => 60, '24h' => 1440];

    public function __construct(
        private ExamRepository $exams,
        private ExamTargetRepository $targets,
        private ExamAttemptRepository $attempts,
        private NotificationService $notifications
    ) {
    }

    /** @return array{opens:int,closes:int} عدد الإشعارات اللي اتبعتت. */
    public function sweep(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy();
        $horizon = $now->copy()->addMinutes(max(self::STAGES));

        $opens = 0;
        foreach ($this->exams->opensBetween($now, $horizon) as $exam) {
            $stage = $this->stageFor($now, $exam->start_at);
            if ($stage === null) {
                continue;
            }
            foreach ($this->targets->recipientsForExamSaved($exam->id, $exam->university_id) as $r) {
                if ($this->claim($exam->id, $r['user_id'], 'opens_' . $stage, $exam->start_at)) {
                    $this->notifications->notify(
                        $r['user_id'],
                        'exam_starts_soon',
                        'Exam opens in ' . $this->label($stage) . ': ' . $exam->title,
                        '"' . $exam->title . '" opens at ' . $exam->start_at->format('Y-m-d H:i') . '.',
                        '/student/my-exams/' . $exam->id,
                        'high'
                    );
                    $opens++;
                }
            }
        }

        $closes = 0;
        foreach ($this->exams->closesBetween($now, $horizon) as $exam) {
            $stage = $this->stageFor($now, $exam->end_at);
            if ($stage === null) {
                continue;
            }
            $finished = ExamAttempt::where('exam_id', $exam->id)->whereIn('status', ExamAttempt::FINISHED_STATUSES)
                ->selectRaw('student_id, COUNT(*) as c')->groupBy('student_id')->pluck('c', 'student_id')->all();
            $running = ExamAttempt::where('exam_id', $exam->id)->where('status', 'in_progress')->pluck('student_id')->flip()->all();
            $overrides = $this->attempts->overridesForExam($exam->id);

            foreach ($this->targets->recipientsForExamSaved($exam->id, $exam->university_id) as $r) {
                $sid = $r['student_id'];
                if (isset($running[$sid])) {
                    continue;
                }
                $allowed = (int) $exam->max_attempts + (isset($overrides[$sid]) ? (int) $overrides[$sid]->extra_attempts : 0);
                if ((int) ($finished[$sid] ?? 0) >= $allowed) {
                    continue;
                }
                if ($this->claim($exam->id, $r['user_id'], 'closes_' . $stage, $exam->end_at)) {
                    $this->notifications->notify(
                        $r['user_id'],
                        'exam_deadline_approaching',
                        'Exam closes in ' . $this->label($stage) . ': ' . $exam->title,
                        '"' . $exam->title . '" closes at ' . $exam->end_at->format('Y-m-d H:i') . '.',
                        '/student/my-exams/' . $exam->id,
                        'high'
                    );
                    $closes++;
                }
            }
        }

        return ['opens' => $opens, 'closes' => $closes];
    }

    /** المرحلة الأقرب اللي الوقت المتبقي دخل فيها، أو null لو برّه كل المراحل/الموعد عدى. */
    public function stageFor(Carbon $now, $target): ?string
    {
        $minutes = $now->diffInMinutes($target, false);
        if ($minutes <= 0) {
            return null;
        }
        foreach (self::STAGES as $name => $limit) {
            if ($minutes <= $limit) {
                return $name;
            }
        }
        return null;
    }

    /** true لو ده أول طلب لتذكير (exam, user, kind, target_at) — أي طلب تاني متزامن/لاحق بيرجع false. */
    private function claim(int $examId, int $userId, string $kind, $targetAt): bool
    {
        return DB::table('exam_reminders_sent')->insertOrIgnore([
            'exam_id' => $examId, 'user_id' => $userId, 'kind' => $kind,
            'target_at' => $targetAt, 'created_at' => now(),
        ]) === 1;
    }

    private function label(string $stage): string
    {
        return $stage === '1h' ? '1 hour' : '24 hours';
    }
}
