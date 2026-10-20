<?php

namespace App\Repositories;

use App\Models\ExamGrade;
use App\Models\ExamGradeHistory;
use Illuminate\Support\Facades\DB;

/**
 * Exam & Assessment System — Round 4 (Grading Core). كل استعلامات
 * exam_grades + exam_grade_history من هنا — نفس نمط ExamAttemptRepository
 * (attempts + answers في كلاس واحد؛ هنا grades + grade_history).
 */
class ExamGradeRepository
{
    /** @return ExamGrade[] كل صفوف التصحيح لمحاولة واحدة، keyed بـ exam_question_id. */
    public function forAttempt($attemptId): array
    {
        return ExamGrade::where('exam_attempt_id', $attemptId)->get()->keyBy('exam_question_id')->all();
    }

    public function find($id): ?ExamGrade
    {
        return ExamGrade::find($id);
    }

    public function findForQuestion($attemptId, $examQuestionId): ?ExamGrade
    {
        return ExamGrade::where('exam_attempt_id', $attemptId)->where('exam_question_id', $examQuestionId)->first();
    }

    /** Ownership-checked lookup — بيتأكد إن صف التصحيح ده تابع لمحاولة على امتحان بعينه (الملكية الفعلية بتتفحص على مستوى الامتحان في الخدمة). */
    public function findForQuestionInAttempt($attemptId, $examQuestionId): ?ExamGrade
    {
        return $this->findForQuestion($attemptId, $examQuestionId);
    }

    /** Upsert — بيستخدم سواء في auto-grade (source=automatic) أو manual grade (source=instructor). */
    public function upsert($attemptId, $examQuestionId, array $data): ExamGrade
    {
        $grade = $this->findForQuestion($attemptId, $examQuestionId);
        if ($grade) {
            $grade->fill($data);
            $grade->save();
            return $grade;
        }

        return ExamGrade::create(array_merge($data, [
            'exam_attempt_id'  => $attemptId,
            'exam_question_id' => $examQuestionId,
        ]));
    }

    public function logHistory(array $data): ExamGradeHistory
    {
        return ExamGradeHistory::create($data);
    }

    /** @return ExamGradeHistory[] تاريخ صف تصحيح واحد، الأحدث أولاً. */
    public function historyFor($examGradeId): array
    {
        return ExamGradeHistory::where('exam_grade_id', $examGradeId)->orderByDesc('changed_at')->get()->all();
    }

    /** true لو كل أسئلة الامتحان (عدد exam_questions) عندها صف تصحيح بدرجة فعلية (مش pending) لمحاولة معينة. */
    public function isFullyGraded($attemptId, int $expectedQuestionCount): bool
    {
        $gradedCount = ExamGrade::where('exam_attempt_id', $attemptId)->whereNotNull('marks_awarded')->count();
        return $expectedQuestionCount > 0 && $gradedCount >= $expectedQuestionCount;
    }

    public function sumAwarded($attemptId): float
    {
        return (float) (ExamGrade::where('exam_attempt_id', $attemptId)->whereNotNull('marks_awarded')->sum('marks_awarded') ?? 0);
    }

    /**
     * ملخص تصحيح كل محاولات امتحان واحد — للمدرس (indexAttempts). صف واحد
     * لكل محاولة: عدد الأسئلة المصححة من إجمالي عدد أسئلة الامتحان.
     * @return array<int,array{attempt_id:int,graded_count:int}>
     */
    public function gradedCountsForExam($examId): array
    {
        return DB::table('exam_grades as g')
            ->join('exam_attempts as a', 'a.id', '=', 'g.exam_attempt_id')
            ->where('a.exam_id', $examId)
            ->whereNotNull('g.marks_awarded')
            ->select('g.exam_attempt_id as attempt_id', DB::raw('COUNT(*) as graded_count'))
            ->groupBy('g.exam_attempt_id')
            ->get()
            ->keyBy('attempt_id')
            ->map(fn ($row) => (int) $row->graded_count)
            ->all();
    }

    /**
     * Round 8 (Phase 27 — Question-level analytics). صف واحد لكل
     * exam_question عليه على الأقل درجة فعلية واحدة (marks_awarded مش
     * null) — is_correct بيتجمع بس لأسئلة MCQ/multi_select/true_false
     * (الموضوعية)، وبيفضل NULL للأسئلة الغير موضوعية (essay/short_answer
     * بتصحيح AI/يدوي) عشان correct%/incorrect% متتحسبش على بيانات مش
     * منطقية أصلاً — avg_marks/max_marks (متاحين للنوعين) هما مقياس
     * الصعوبة الموحّد (average_score_pct في ExamAnalyticsService).
     * @return array<int,object{exam_question_id:int,graded_count:int,correct_count:int,incorrect_count:int,avg_marks:float,avg_max_marks:float}>
     */
    public function questionStatsForExam($examId): array
    {
        return DB::table('exam_grades as g')
            ->join('exam_questions as eq', 'eq.id', '=', 'g.exam_question_id')
            ->where('eq.exam_id', $examId)
            ->whereNotNull('g.marks_awarded')
            ->select(
                'g.exam_question_id',
                DB::raw('COUNT(*) as graded_count'),
                DB::raw('SUM(CASE WHEN g.is_correct = 1 THEN 1 ELSE 0 END) as correct_count'),
                DB::raw('SUM(CASE WHEN g.is_correct = 0 THEN 1 ELSE 0 END) as incorrect_count'),
                DB::raw('AVG(g.marks_awarded) as avg_marks'),
                DB::raw('AVG(g.max_marks) as avg_max_marks')
            )
            ->groupBy('g.exam_question_id')
            ->get()
            ->keyBy('exam_question_id')
            ->all();
    }
}
