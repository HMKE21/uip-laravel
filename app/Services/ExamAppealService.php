<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamGradeAppeal;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamGradeRepository;
use App\Repositories\StudentRepository;
use Illuminate\Support\Facades\DB;

/**
 * تظلم الطالب على الدرجة. الطالب يقدر يتظلم (على الدرجة الكلية أو سؤال بعينه) بس لو: التظلم شغال على
 * الامتحان، النتيجة ظاهرة له فعلًا، جوه نافذة الأيام (من وقت ظهور النتيجة)، مفيش تظلم pending لنفس النطاق،
 * وعدد التظلمات (غير المسحوبة) على المحاولة ≤ MAX_PER_ATTEMPT. المدرس بيقبل/يرفض مع رد؛ القبول على سؤال
 * ممكن يحدّث درجته في نفس الـ transaction عن طريق ExamGradingService::gradeManually (فبيتسجل في grade history).
 */
class ExamAppealService
{
    public const MAX_PER_ATTEMPT = 5;
    public const STATUSES = ['pending', 'accepted', 'rejected', 'withdrawn'];

    public function __construct(
        private ExamGradingService $grading,
        private ExamAttemptRepository $attempts,
        private ExamGradeRepository $grades,
        private StudentRepository $students,
        private AcademicStaffRepository $staffRepo,
        private NotificationService $notifications,
        private AuditLogService $auditLog
    ) {
    }

    /** بداية نافذة التظلم = أول لحظة النتيجة بقت ظاهرة للطالب. */
    public function windowStart(Exam $exam, ExamAttempt $attempt)
    {
        return match ($exam->result_visibility) {
            'manual'      => $exam->results_published_at,
            'after_close' => $exam->end_at && $exam->end_at->greaterThan($attempt->updated_at) ? $exam->end_at : $attempt->updated_at,
            default       => $attempt->updated_at,
        };
    }

    /** @return array{allowed:bool,reason:?string,window_ends_at:mixed} */
    public function eligibility(Exam $exam, ExamAttempt $attempt): array
    {
        $ends = null;
        $start = $this->windowStart($exam, $attempt);
        if ($start) {
            $ends = $start->copy()->addDays(max(1, (int) $exam->appeal_window_days));
        }
        $deny = fn (string $r) => ['allowed' => false, 'reason' => $r, 'window_ends_at' => $ends];

        if (!$exam->appeals_enabled) {
            return $deny('Appeals are not enabled for this exam.');
        }
        if (!$this->grading->isResultVisibleTo($exam, $attempt)) {
            return $deny('Your result is not available yet.');
        }
        if ($ends === null || now()->greaterThan($ends)) {
            return $deny('The appeal window for this exam has closed.');
        }
        $used = ExamGradeAppeal::where('exam_attempt_id', $attempt->id)->where('status', '!=', 'withdrawn')->count();
        if ($used >= self::MAX_PER_ATTEMPT) {
            return $deny('You have reached the maximum number of appeals for this attempt.');
        }

        return ['allowed' => true, 'reason' => null, 'window_ends_at' => $ends];
    }

    /** @return array<string,mixed> ما يحتاجه الفرونت لعرض زر التظلم + تظلمات الطالب على المحاولة. */
    public function forAttempt(Exam $exam, ExamAttempt $attempt): array
    {
        $elig = $this->eligibility($exam, $attempt);
        $rows = ExamGradeAppeal::where('exam_attempt_id', $attempt->id)->orderByDesc('id')->get();

        return [
            'enabled'        => (bool) $exam->appeals_enabled,
            'can_appeal'     => $elig['allowed'],
            'blocked_reason' => $elig['reason'],
            'window_ends_at' => $elig['window_ends_at'],
            'question_level_allowed' => (bool) $exam->show_answer_review && !$exam->show_score_only,
            'appeals'        => $rows->map(fn ($a) => $this->studentRow($a))->all(),
        ];
    }

    /** @return array<int,array<string,mixed>> كل تظلمات الطالب (كل الامتحانات). */
    public function forStudent(int $studentId): array
    {
        return DB::table('exam_grade_appeals as ap')
            ->join('exams as e', 'e.id', '=', 'ap.exam_id')
            ->where('ap.student_id', $studentId)
            ->orderByDesc('ap.id')
            ->select('ap.*', 'e.title as exam_title')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'exam_id' => (int) $r->exam_id, 'exam_title' => $r->exam_title,
                'attempt_id' => (int) $r->exam_attempt_id, 'exam_question_id' => $r->exam_question_id ? (int) $r->exam_question_id : null,
                'reason' => $r->reason, 'status' => $r->status, 'response' => $r->response,
                'resolved_at' => $r->resolved_at, 'created_at' => $r->created_at,
            ])->all();
    }

    /** @throws \InvalidArgumentException رسالة جاهزة تتعرض للطالب. */
    public function create(Exam $exam, ExamAttempt $attempt, int $studentId, ?int $examQuestionId, string $reason): ExamGradeAppeal
    {
        return DB::transaction(function () use ($exam, $attempt, $studentId, $examQuestionId, $reason) {
            // قفل صف المحاولة عشان ضغطتين متزامنتين ميعدّوش حد التظلمات ولا يكرروا نفس التظلم.
            $locked = ExamAttempt::where('id', $attempt->id)->lockForUpdate()->first();
            $elig = $this->eligibility($exam, $locked);
            if (!$elig['allowed']) {
                throw new \InvalidArgumentException($elig['reason']);
            }
            if ($examQuestionId !== null) {
                if (!$exam->show_answer_review || $exam->show_score_only) {
                    throw new \InvalidArgumentException('This exam does not show the question breakdown, so you can only appeal the overall score.');
                }
                if (!$this->attempts->pivotForAttempt($locked->id, $examQuestionId)) {
                    throw new \InvalidArgumentException('Question not found in this attempt.');
                }
            }
            $dup = ExamGradeAppeal::where('exam_attempt_id', $locked->id)->where('status', 'pending')
                ->when($examQuestionId === null, fn ($q) => $q->whereNull('exam_question_id'), fn ($q) => $q->where('exam_question_id', $examQuestionId))
                ->exists();
            if ($dup) {
                throw new \InvalidArgumentException('You already have a pending appeal for this.');
            }

            $appeal = ExamGradeAppeal::create([
                'exam_id' => $exam->id, 'exam_attempt_id' => $locked->id, 'exam_question_id' => $examQuestionId,
                'student_id' => $studentId, 'reason' => $reason, 'status' => 'pending',
            ]);

            $staff = $exam->created_by_academic_staff_id ? $this->staffRepo->find($exam->created_by_academic_staff_id) : null;
            if ($staff) {
                $this->notifications->notify($staff->user_id, 'exam_appeal_submitted', 'New grade appeal: ' . $exam->title,
                    'A student submitted a grade appeal on "' . $exam->title . '".', '/academic-staff/exams/' . $exam->id . '/appeals', 'normal');
            }
            return $appeal;
        });
    }

    /** @throws \InvalidArgumentException */
    public function withdraw(ExamAttempt $attempt, int $appealId): ExamGradeAppeal
    {
        $appeal = ExamGradeAppeal::where('id', $appealId)->where('exam_attempt_id', $attempt->id)->first();
        if (!$appeal) {
            throw new \InvalidArgumentException('Appeal not found.');
        }
        if ($appeal->status !== 'pending') {
            throw new \InvalidArgumentException('Only pending appeals can be withdrawn.');
        }
        $appeal->status = 'withdrawn';
        $appeal->resolved_at = now();
        $appeal->save();
        return $appeal;
    }

    // -----------------------------------------------------------------
    // Instructor
    // -----------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function listForExam(Exam $exam, ?string $status = null): array
    {
        $q = DB::table('exam_grade_appeals as ap')
            ->join('exam_attempts as a', 'a.id', '=', 'ap.exam_attempt_id')
            ->join('students as s', 's.id', '=', 'ap.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('exam_questions as eq', 'eq.id', '=', 'ap.exam_question_id')
            ->leftJoin('questions as qq', 'qq.id', '=', 'eq.question_id')
            ->where('ap.exam_id', $exam->id)
            ->orderByRaw("case when ap.status = 'pending' then 0 else 1 end")
            ->orderByDesc('ap.id')
            ->select('ap.*', 'a.score as attempt_score', 'a.percentage as attempt_percentage', 's.student_number', 'u.full_name', 'qq.prompt as question_prompt', 'qq.marks as question_marks', 'eq.marks_override');
        if ($status !== null) {
            $q->where('ap.status', $status);
        }

        return $q->limit(500)->get()->map(fn ($r) => [
            'id' => (int) $r->id,
            'attempt_id' => (int) $r->exam_attempt_id,
            'exam_question_id' => $r->exam_question_id ? (int) $r->exam_question_id : null,
            'question_prompt' => $r->question_prompt,
            'question_max_marks' => $r->exam_question_id ? (float) ($r->marks_override ?? $r->question_marks) : null,
            'student_number' => $r->student_number,
            'student_name' => $r->full_name,
            'attempt_score' => $r->attempt_score !== null ? (float) $r->attempt_score : null,
            'attempt_percentage' => $r->attempt_percentage !== null ? (float) $r->attempt_percentage : null,
            'reason' => $r->reason, 'status' => $r->status, 'response' => $r->response,
            'score_before' => $r->score_before !== null ? (float) $r->score_before : null,
            'score_after' => $r->score_after !== null ? (float) $r->score_after : null,
            'resolved_at' => $r->resolved_at, 'created_at' => $r->created_at,
        ])->all();
    }

    /** @return array<string,int> */
    public function countsForExam(Exam $exam): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (DB::table('exam_grade_appeals')->where('exam_id', $exam->id)->select('status', DB::raw('count(*) as c'))->groupBy('status')->get() as $r) {
            $counts[$r->status] = (int) $r->c;
        }
        return $counts;
    }

    /**
     * @param float|null $newMarks لو اتحدد (تظلم على سؤال + قبول) الدرجة بتتحدّث بنفس الـ transaction.
     * @throws \InvalidArgumentException
     */
    public function resolve(Exam $exam, int $appealId, string $decision, ?string $response, ?float $newMarks, $staff): ExamGradeAppeal
    {
        if (!in_array($decision, ['accepted', 'rejected'], true)) {
            throw new \InvalidArgumentException('Decision must be accepted or rejected.');
        }

        return DB::transaction(function () use ($exam, $appealId, $decision, $response, $newMarks, $staff) {
            // الملكية: التظلم لازم يتبع الامتحان ده (اللي المدرس أثبت إنه بتاعه).
            $appeal = ExamGradeAppeal::where('id', $appealId)->where('exam_id', $exam->id)->lockForUpdate()->first();
            if (!$appeal) {
                throw new \InvalidArgumentException('Appeal not found.');
            }
            if ($appeal->status !== 'pending') {
                throw new \InvalidArgumentException('This appeal has already been resolved.');
            }
            if ($decision === 'rejected' && ($response === null || trim($response) === '')) {
                throw new \InvalidArgumentException('Please explain the reason for rejecting the appeal.');
            }
            if ($newMarks !== null && ($decision !== 'accepted' || $appeal->exam_question_id === null)) {
                throw new \InvalidArgumentException('New marks can only be set when accepting a question-level appeal.');
            }

            if ($newMarks !== null) {
                $attempt = ExamAttempt::findOrFail($appeal->exam_attempt_id);
                $before = $this->grades->findForQuestion($attempt->id, $appeal->exam_question_id);
                $appeal->score_before = $before?->marks_awarded;
                $grade = $this->grading->gradeManually($attempt, $appeal->exam_question_id, $newMarks, $before?->feedback, $staff, 'Appeal #' . $appeal->id . ' accepted');
                $appeal->score_after = $grade->marks_awarded;
            }

            $appeal->status = $decision;
            $appeal->response = $response;
            $appeal->resolved_by_academic_staff_id = $staff->id;
            $appeal->resolved_at = now();
            $appeal->save();

            $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.appeal_' . $decision, 'ExamGradeAppeal', $appeal->id, ['status' => 'pending'],
                ['status' => $decision, 'new_marks' => $newMarks]);

            $student = $this->students->find($appeal->student_id);
            if ($student) {
                $this->notifications->notify($student->user_id, 'exam_appeal_' . $decision, 'Appeal ' . $decision . ': ' . $exam->title,
                    'Your grade appeal on "' . $exam->title . '" was ' . $decision . '.', '/student/exam-attempt/' . $appeal->exam_attempt_id . '/result', 'normal');
            }

            return $appeal;
        });
    }

    public function updateSettings(Exam $exam, bool $enabled, int $days, $staff): Exam
    {
        $old = ['appeals_enabled' => (bool) $exam->appeals_enabled, 'appeal_window_days' => (int) $exam->appeal_window_days];
        $exam->appeals_enabled = $enabled;
        $exam->appeal_window_days = $days;
        $exam->save();
        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.appeal_settings_changed', 'Exam', $exam->id, $old,
            ['appeals_enabled' => $enabled, 'appeal_window_days' => $days]);
        return $exam;
    }

    private function studentRow(ExamGradeAppeal $a): array
    {
        return [
            'id' => $a->id, 'exam_question_id' => $a->exam_question_id, 'reason' => $a->reason, 'status' => $a->status,
            'response' => $a->response, 'resolved_at' => $a->resolved_at, 'created_at' => $a->created_at,
        ];
    }
}
