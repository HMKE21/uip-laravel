<?php

namespace App\Services;

use App\Models\Exam;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamGradeRepository;
use App\Repositories\ExamRepository;
use App\Repositories\ExamTargetRepository;

/**
 * Exam & Assessment System — Round 8 (Phases 25/26/27/28). كل الحسابات
 * هنا "compute on demand" من البيانات الفعلية (exam_attempts/exam_grades) —
 * مفيش جدول تجميع (aggregate/cache) منفصل، زي ما ExamGradingService::
 * listAttemptsForExam() بيشتغل من الأول. "Only implement metrics that can
 * be reliably calculated from the actual data" (Phase 27) بيترجم هنا لحاجتين:
 * (1) percentage/pass-rate بيتحسبوا بس من محاولات status=graded (مش
 * submitted/grading لسه)، و(2) correct%/incorrect% بيتحسبوا بس للأسئلة
 * الموضوعية (mcq/multi_select/true_false) — is_correct دايمًا null
 * للأسئلة الغير موضوعية (essay/short_answer)، فمتحسبش نسبة "صح/غلط" مالهاش
 * معنى؛ average_score_pct (marks_awarded/max_marks) هو المقياس الموحّد
 * اللي بيشتغل مع النوعين، وهو أساس "أصعب/أسهل سؤال" (Phase 27).
 *
 * examAnalytics() هي نقطة الدخول الرئيسية — بتغطي Phase 25 (Instructor
 * Exam Archive: Total/Started/Submitted/Not Submitted/Graded/Average/
 * Highest/Lowest/Pass Rate) وPhase 27 (نفس الأرقام + question-level) في
 * استدعاء واحد، عشان الفرونت مايحتاجش endpoint منفصل لكل شاشة.
 *
 * facultyExams()/universityExamsScope() (Phase 26) بيرجّعوا الامتحانات في
 * نطاق كلية/جامعة كاملة — بغض النظر عن مين من أعضاء هيئة التدريس عملها
 * (عكس ExamSystemService::listExams() اللي مقيدة بعضو واحد بعينه). الملكية
 * (إن الكلية/الجامعة دي فعلاً بتاعة اليوزر) بتتفحص في الكنترولر قبل ما
 * الميثودز دي تتنادى — نفس نمط findOwnedExam() في باقي الخدمة.
 */
class ExamAnalyticsService
{
    /** الأنواع اللي is_correct بتاعها معنى فعلي (تصحيح all-or-nothing) — راجع ExamGradingService::evaluateObjectiveAnswer(). */
    private const OBJECTIVE_TYPES = ['mcq', 'multi_select', 'true_false'];

    public function __construct(
        private ExamRepository $exams,
        private ExamAttemptRepository $attempts,
        private ExamGradeRepository $grades,
        private ExamTargetRepository $targets
    ) {
    }

    // -----------------------------------------------------------------
    // Phases 25/27 — exam-level + question-level analytics
    // -----------------------------------------------------------------

    public function examAnalytics(Exam $exam): array
    {
        $attemptRows = $this->attempts->forExamWithStudentInfo($exam->id);

        $totalStudents = $this->targets->countForExamSaved($exam->id, $exam->university_id);
        $totalAttempts = count($attemptRows);

        $inProgress = array_filter($attemptRows, fn ($r) => $r->status === 'in_progress');
        $submitted = array_filter($attemptRows, fn ($r) => in_array($r->status, ['submitted', 'auto_submitted', 'grading', 'graded'], true));
        $graded = array_filter($attemptRows, fn ($r) => $r->status === 'graded');

        $percentages = array_values(array_filter(
            array_map(fn ($r) => $r->percentage !== null ? (float) $r->percentage : null, $graded),
            fn ($p) => $p !== null
        ));
        sort($percentages);
        $n = count($percentages);

        $passingScore = $exam->passing_score !== null ? (float) $exam->passing_score : null;
        $passCount = $passingScore !== null ? count(array_filter($percentages, fn ($p) => $p >= $passingScore)) : null;

        $durations = [];
        foreach ($submitted as $r) {
            if ($r->started_at && $r->submitted_at) {
                $durations[] = \Carbon\Carbon::parse($r->started_at)->diffInSeconds(\Carbon\Carbon::parse($r->submitted_at));
            }
        }

        // 10 شرائح × 10% للـ histogram في صفحة التحليلات (100% بتتحسب في آخر شريحة).
        $bins = array_fill(0, 10, 0);
        foreach ($percentages as $p) {
            $bins[min(9, max(0, (int) floor($p / 10)))]++;
        }
        $distribution = [];
        foreach ($bins as $i => $count) {
            $distribution[] = ['from' => $i * 10, 'to' => ($i + 1) * 10, 'count' => $count];
        }

        // صف لكل محاولة (غير الملغية) — الفرونت بيفلتر/يرتّب منها جدول الطلاب من غير طلب إضافي.
        $studentResults = [];
        foreach ($attemptRows as $r) {
            if ($r->status === 'cancelled') {
                continue;
            }
            $pct = $r->percentage !== null ? (float) $r->percentage : null;
            $seconds = ($r->started_at && $r->submitted_at)
                ? \Carbon\Carbon::parse($r->started_at)->diffInSeconds(\Carbon\Carbon::parse($r->submitted_at))
                : null;
            $studentResults[] = [
                'attempt_id'     => (int) $r->id,
                'student_id'     => (int) $r->student_id,
                'full_name'      => $r->full_name,
                'student_number' => $r->student_number,
                'attempt_number' => (int) $r->attempt_number,
                'status'         => $r->status,
                'score'          => $r->score !== null ? (float) $r->score : null,
                'percentage'     => $pct,
                'passed'         => ($r->status === 'graded' && $pct !== null && $passingScore !== null) ? $pct >= $passingScore : null,
                'time_seconds'   => $seconds,
                'is_late'        => (bool) ($r->is_late ?? false),
                'submitted_at'   => $r->submitted_at,
            ];
        }

        return [
            'exam_id'              => $exam->id,
            'exam_title'           => $exam->title,
            'passing_score'        => $passingScore,
            'score_distribution'   => $distribution,
            'student_results'      => $studentResults,
            'total_students'       => $totalStudents,
            'total_attempts'       => $totalAttempts,
            'started_count'        => $totalAttempts,
            'in_progress_count'    => count($inProgress),
            'submitted_count'      => count($submitted),
            'graded_count'         => count($graded),
            'not_submitted_count'  => max(0, $totalStudents - $totalAttempts),
            'submission_rate'      => $totalStudents > 0 ? round(count($submitted) / $totalStudents * 100, 2) : null,
            'average_score'        => $n > 0 ? round(array_sum($percentages) / $n, 2) : null,
            'median_score'         => $this->median($percentages),
            'highest_score'        => $n > 0 ? round(max($percentages), 2) : null,
            'lowest_score'         => $n > 0 ? round(min($percentages), 2) : null,
            'pass_rate'            => ($passingScore !== null && $n > 0) ? round($passCount / $n * 100, 2) : null,
            'failure_rate'         => ($passingScore !== null && $n > 0) ? round(($n - $passCount) / $n * 100, 2) : null,
            'average_time_seconds' => $durations ? (int) round(array_sum($durations) / count($durations)) : null,
            'questions'            => $this->questionAnalytics($exam),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function questionAnalytics(Exam $exam): array
    {
        // كل exam_questions rows اللي "استُخدمت فعليًا" في الامتحان ده —
        // يدوي + كل سؤال اتسحب من أي pool لأي طالب (dedup على question_id،
        // راجع ExamRepository::findOrCreateQuestionForPool()). مش
        // attempt-scoped عمدًا هنا (عكس Round 7) — عايزين كل سؤال ظهر لأي
        // طالب على الإطلاق، مش أسئلة محاولة واحدة بعينها.
        $pivots = $this->exams->questionsFor($exam->id);
        $stats = $this->grades->questionStatsForExam($exam->id);

        $rows = array_map(function ($pivot) use ($stats) {
            $s = $stats[$pivot->id] ?? null;
            $question = $pivot->question;
            $isObjective = in_array($question->type, self::OBJECTIVE_TYPES, true);
            $gradedCount = $s ? (int) $s->graded_count : 0;
            $avgMarks = $s ? (float) $s->avg_marks : null;
            $avgMaxMarks = $s ? (float) $s->avg_max_marks : null;

            return [
                'exam_question_id'  => $pivot->id,
                'question_id'       => $question->id,
                'type'              => $question->type,
                'prompt'            => mb_strlen($question->prompt) > 200 ? mb_substr($question->prompt, 0, 200) . '…' : $question->prompt,
                'max_marks'         => (float) ($pivot->marks_override ?? $question->marks),
                'answered_count'    => $gradedCount,
                'correct_pct'       => ($isObjective && $gradedCount > 0) ? round(((int) $s->correct_count) / $gradedCount * 100, 2) : null,
                'incorrect_pct'     => ($isObjective && $gradedCount > 0) ? round(((int) $s->incorrect_count) / $gradedCount * 100, 2) : null,
                'average_score_pct' => ($gradedCount > 0 && $avgMaxMarks > 0) ? round($avgMarks / $avgMaxMarks * 100, 2) : null,
            ];
        }, $pivots);

        $scored = array_values(array_filter($rows, fn ($r) => $r['average_score_pct'] !== null));
        $hardest = $scored ? array_reduce($scored, fn ($a, $b) => ($a === null || $b['average_score_pct'] < $a['average_score_pct']) ? $b : $a) : null;
        $easiest = $scored ? array_reduce($scored, fn ($a, $b) => ($a === null || $b['average_score_pct'] > $a['average_score_pct']) ? $b : $a) : null;

        return [
            'items'                    => $rows,
            'most_difficult_question'  => $hardest ? $hardest['exam_question_id'] : null,
            'easiest_question'         => $easiest ? $easiest['exam_question_id'] : null,
        ];
    }

    private function median(array $sortedValues): ?float
    {
        $n = count($sortedValues);
        if ($n === 0) {
            return null;
        }
        if ($n % 2 === 1) {
            return round($sortedValues[intdiv($n, 2)], 2);
        }
        return round(($sortedValues[$n / 2 - 1] + $sortedValues[$n / 2]) / 2, 2);
    }

    // -----------------------------------------------------------------
    // Phase 26 — University / College Results
    // -----------------------------------------------------------------

    /** @return array<int,array<string,mixed>> كل امتحانات كلية واحدة + ملخص أرقام سريع لكل واحد. */
    public function facultyExams($facultyId): array
    {
        return array_map(fn (Exam $e) => $this->examListRow($e), $this->exams->forFaculty($facultyId));
    }

    /** @return array<int,array<string,mixed>> كل امتحانات جامعة واحدة بالكامل (كل الكليات) + ملخص أرقام سريع لكل واحد. */
    public function universityExamsScope($universityId): array
    {
        return array_map(fn (Exam $e) => $this->examListRow($e), $this->exams->forUniversityScope($universityId));
    }

    private function examListRow(Exam $exam): array
    {
        $attemptRows = $this->attempts->forExamWithStudentInfo($exam->id);
        $graded = array_filter($attemptRows, fn ($r) => $r->status === 'graded');
        $percentages = array_values(array_filter(array_map(fn ($r) => $r->percentage !== null ? (float) $r->percentage : null, $graded), fn ($p) => $p !== null));

        return [
            'id'              => $exam->id,
            'title'           => $exam->title,
            'subject'         => $exam->subject,
            'faculty_id'      => $exam->faculty_id,
            'department_id'   => $exam->department_id,
            'status'          => $exam->status,
            'total_marks'     => (float) $exam->total_marks,
            'total_attempts'  => count($attemptRows),
            'graded_count'    => count($graded),
            'average_score'   => $percentages ? round(array_sum($percentages) / count($percentages), 2) : null,
        ];
    }

    // -----------------------------------------------------------------
    // Phase 28 — Student Dashboard
    // -----------------------------------------------------------------

    /**
     * لوحة الطالب — Upcoming/Active/Completed Exams + Recent Results +
     * Average Score. exams->find()/targets->examIdsForStudent() نفس
     * مصدر StudentExamService::listMyExams() بالظبط (Round 2) — هنا
     * بنجمّع أرقام بس، مش بنعيد كشف محتوى الامتحان. recent_results
     * بتحترم نفس منطق ExamGradingService::isResultVisibleTo() حرفيًا
     * (مُكرر هنا عمدًا على صفوف SQL خام بدل ما نحمّل Exam+ExamAttempt
     * models لكل صف — استدعاء ExamGradingService من هنا كان هيعمل
     * تبعية دائرية مع الـ dashboard اللي المفروض قراءة-فقط وخفيفة)،
     * عشان طالب ميشوفش نتيجة امتحان قبل أوانها حتى في شاشة اللوحة.
     */
    public function studentDashboard($studentId, $universityId): array
    {
        $examIds = $this->targets->examIdsForStudent($studentId, $universityId);
        $exams = array_values(array_filter(
            array_map(fn ($id) => $this->exams->find($id), $examIds),
            fn (?Exam $e) => $e !== null && in_array($e->status, ['published', 'scheduled'], true)
        ));

        $upcoming = [];
        $active = [];
        foreach ($exams as $exam) {
            if ($exam->status === 'scheduled' || ($exam->start_at && $exam->start_at->isFuture())) {
                $upcoming[] = $exam;
            } elseif ($exam->end_at === null || $exam->end_at->isFuture()) {
                $active[] = $exam;
            }
        }

        $attemptRows = $this->attempts->forStudent($studentId);
        $finishedExamIds = [];
        $percentages = [];
        $recentResults = [];
        foreach ($attemptRows as $row) {
            if (in_array($row->status, \App\Models\ExamAttempt::FINISHED_STATUSES, true)) {
                $finishedExamIds[$row->exam_id] = true;
            }
            if ($this->rowResultIsVisible($row)) {
                $percentages[] = (float) $row->percentage;
                $recentResults[] = [
                    'exam_id'      => $row->exam_id,
                    'exam_title'   => $row->exam_title,
                    'percentage'   => (float) $row->percentage,
                    'submitted_at' => $row->submitted_at,
                ];
            }
        }
        usort($recentResults, fn ($a, $b) => strcmp((string) $b['submitted_at'], (string) $a['submitted_at']));

        return [
            'upcoming_exams'        => array_map(fn (Exam $e) => $this->studentExamRow($e), $upcoming),
            'active_exams'          => array_map(fn (Exam $e) => $this->studentExamRow($e), $active),
            'completed_exams_count' => count($finishedExamIds),
            'recent_results'        => array_slice($recentResults, 0, 10),
            'average_score'         => $percentages ? round(array_sum($percentages) / count($percentages), 2) : null,
        ];
    }

    /** نفس ExamGradingService::isResultVisibleTo() بالظبط، بس على صف SQL خام (بدون تحميل Exam/ExamAttempt models). */
    private function rowResultIsVisible($row): bool
    {
        if ($row->status !== 'graded' || $row->percentage === null) {
            return false;
        }
        return match ($row->result_visibility) {
            'immediate'   => true,
            'after_close' => $row->end_at === null || now()->greaterThanOrEqualTo(\Carbon\Carbon::parse($row->end_at)),
            'manual'      => $row->results_published_at !== null && now()->greaterThanOrEqualTo(\Carbon\Carbon::parse($row->results_published_at)),
            default       => false,
        };
    }

    private function studentExamRow(Exam $exam): array
    {
        return [
            'id'                => $exam->id,
            'title'             => $exam->title,
            'subject'           => $exam->subject,
            'start_at'          => $exam->start_at,
            'end_at'            => $exam->end_at,
            'duration_minutes'  => $exam->duration_minutes,
            'status'            => $exam->status,
        ];
    }

    // -----------------------------------------------------------------
    // Phase 28 — Exam Dashboards (widgets عامة، مش سطح "امتحان واحد")
    // -----------------------------------------------------------------

    /** لوحة المدرس — My Exams / Active Exams / Pending Grading / Recent Results / إحصائيات سريعة. */
    public function instructorDashboard($academicStaffId): array
    {
        $exams = $this->exams->forCreator($academicStaffId);
        $active = array_values(array_filter($exams, fn (Exam $e) => in_array($e->status, ['published', 'scheduled'], true)));

        $pendingGrading = 0;
        $recentResults = [];
        foreach ($exams as $exam) {
            $rows = $this->attempts->forExamWithStudentInfo($exam->id);
            $pendingGrading += count(array_filter($rows, fn ($r) => $r->status === 'grading'));
            foreach (array_filter($rows, fn ($r) => $r->status === 'graded') as $r) {
                $recentResults[] = [
                    'exam_id' => $exam->id, 'exam_title' => $exam->title,
                    'student_name' => $r->full_name, 'percentage' => $r->percentage !== null ? (float) $r->percentage : null,
                    'submitted_at' => $r->submitted_at,
                ];
            }
        }
        usort($recentResults, fn ($a, $b) => strcmp((string) $b['submitted_at'], (string) $a['submitted_at']));

        return [
            'total_exams'      => count($exams),
            'active_exams'     => count($active),
            'pending_grading'  => $pendingGrading,
            'recent_results'   => array_slice($recentResults, 0, 10),
        ];
    }

    /** لوحة الكلية/الجامعة — Total Exams / Total Students / Total Attempts / Average Performance / Pass Rate، على نطاق الامتحانات المُمررة. */
    public function scopedDashboard(array $exams): array
    {
        $totalAttempts = 0;
        $percentages = [];
        $studentIds = [];
        $passCount = 0;
        $passableCount = 0;

        foreach ($exams as $exam) {
            $rows = $this->attempts->forExamWithStudentInfo($exam->id);
            $totalAttempts += count($rows);
            foreach ($rows as $r) {
                $studentIds[(int) $r->student_id] = true;
                if ($r->status === 'graded' && $r->percentage !== null) {
                    $percentages[] = (float) $r->percentage;
                    if ($exam->passing_score !== null) {
                        $passableCount++;
                        if ((float) $r->percentage >= (float) $exam->passing_score) {
                            $passCount++;
                        }
                    }
                }
            }
        }

        return [
            'total_exams'          => count($exams),
            'total_students'       => count($studentIds),
            'total_attempts'       => $totalAttempts,
            'average_performance'  => $percentages ? round(array_sum($percentages) / count($percentages), 2) : null,
            'pass_rate'            => $passableCount > 0 ? round($passCount / $passableCount * 100, 2) : null,
        ];
    }
}
