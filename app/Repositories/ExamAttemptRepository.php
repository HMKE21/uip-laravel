<?php

namespace App\Repositories;

use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptQuestion;
use App\Models\ExamQuestion;
use App\Models\ExamStudentOverride;
use Illuminate\Support\Facades\DB;

/**
 * Exam & Assessment System — Round 3، موسّعة في Round 7. كل استعلامات
 * exam_attempts + exam_answers من هنا — نفس نمط ExamRepository (بيغطي
 * exams + exam_questions pivot الاتنين في كلاس واحد) بدل ما نعمل repo
 * رابع منفصل بس عشان answers. findOwned() هنا مقيدة بـ student_id (مش
 * created_by_academic_staff_id زي باقي الـ repos) — الملكية هنا للطالب
 * صاحب المحاولة.
 *
 * Round 7: questionsForAttempt()/pivotForAttempt() هما بدائل exam-level
 * questionsFor()/findPivot() (ExamRepository) اللي كل كود المحاولات
 * والتصحيح اتحول يستخدمها بدل القديمة — عشان الأسئلة ممكن تختلف من
 * طالب لطالب تحت Question Pools (Phase 6/7)، فـ"أسئلة الامتحان" مبقتش
 * كافية، لازم "أسئلة المحاولة دي بالذات".
 */
class ExamAttemptRepository
{
    public function find($id): ?ExamAttempt
    {
        return ExamAttempt::find($id);
    }

    /** Ownership-checked lookup — مقيدة بالطالب صاحب المحاولة نفسه. */
    public function findOwned($id, $studentId): ?ExamAttempt
    {
        $attempt = ExamAttempt::find($id);
        if (!$attempt || (int) $attempt->student_id !== (int) $studentId) {
            return null;
        }
        return $attempt;
    }

    /** @return ExamAttempt[] كل محاولات الطالب ده على امتحان بعينه، الأحدث أولاً. */
    public function forStudentAndExam($studentId, $examId): array
    {
        return ExamAttempt::where('student_id', $studentId)
            ->where('exam_id', $examId)
            ->orderByDesc('attempt_number')
            ->get()
            ->all();
    }

    /** المحاولة الشغالة حاليًا (in_progress) لو فيه واحدة — عشان Start Exam يكمل عليها بدل ما يعمل واحدة جديدة. */
    public function activeAttempt($studentId, $examId): ?ExamAttempt
    {
        return ExamAttempt::where('student_id', $studentId)
            ->where('exam_id', $examId)
            ->whereIn('status', ExamAttempt::ACTIVE_STATUSES)
            ->first();
    }

    /**
     * أي محاولة شغالة للطالب على أي امتحان (مش امتحان بعينه) — أساس "وضع قفل الامتحان":
     * الفرونت بيمنع التنقل/الـ sidebar، والـ AI Assistant بيتقفل من السيرفر طول ما فيه واحدة.
     * المحاولة اللي وقتها عدى مش بتتحسب (هتتحوّل auto_submitted عند أول enforceTimer/sweep).
     */
    public function activeAttemptForStudent($studentId): ?ExamAttempt
    {
        // المحاولة جوه فترة السماح لسه شغالة (الطالب لسه يقدر يسلّم) — بتتستبعد بس لما الوقت + السماح يخلصوا.
        return ExamAttempt::with('exam')
            ->where('student_id', $studentId)
            ->whereIn('status', ExamAttempt::ACTIVE_STATUSES)
            ->orderByDesc('started_at')
            ->get()
            ->first(fn (ExamAttempt $a) => !$a->isHardExpired());
    }

    /** @return int[] ids الطلاب اللي ليهم محاولة واحدة على الأقل على الامتحان ده (أي حالة). */
    public function studentIdsWithAttempts($examId): array
    {
        return ExamAttempt::where('exam_id', $examId)->distinct()->pluck('student_id')->map(fn ($i) => (int) $i)->all();
    }

    /** @return array<int,int> student_id => عدد المحاولات على الامتحان ده. */
    public function attemptCountsByStudent($examId, array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }
        return ExamAttempt::where('exam_id', $examId)->whereIn('student_id', $studentIds)
            ->selectRaw('student_id, COUNT(*) as c')->groupBy('student_id')
            ->pluck('c', 'student_id')->map(fn ($c) => (int) $c)->all();
    }

    public function countFinishedForStudentAndExam($studentId, $examId): int
    {
        return ExamAttempt::where('student_id', $studentId)
            ->where('exam_id', $examId)
            ->whereIn('status', ExamAttempt::FINISHED_STATUSES)
            ->count();
    }

    // -----------------------------------------------------------------
    // Per-student overrides (إعادة الامتحان بصلاحية المدرس)
    // -----------------------------------------------------------------

    public function overrideFor($examId, $studentId): ?ExamStudentOverride
    {
        return ExamStudentOverride::where('exam_id', $examId)->where('student_id', $studentId)->first();
    }

    /** @return array<int,ExamStudentOverride> keyed بـ student_id. */
    public function overridesForExam($examId): array
    {
        return ExamStudentOverride::where('exam_id', $examId)->get()->keyBy('student_id')->all();
    }

    public function saveOverride($examId, $studentId, array $data): ExamStudentOverride
    {
        return ExamStudentOverride::updateOrCreate(
            ['exam_id' => $examId, 'student_id' => $studentId],
            $data
        );
    }

    public function nextAttemptNumber($studentId, $examId): int
    {
        return (int) (ExamAttempt::where('student_id', $studentId)->where('exam_id', $examId)->max('attempt_number') ?? 0) + 1;
    }

    public function create(array $data): ExamAttempt
    {
        return ExamAttempt::create($data);
    }

    /**
     * Round 8 (Phase 28 — Student Dashboard). كل محاولات طالب واحد عبر كل
     * امتحاناته (مش امتحان واحد بعينه زي forStudentAndExam() فوق)، مع
     * عنوان الامتحان محمّل عبر join — StudentDashboard محتاجة "آخر
     * النتائج" و"متوسط الدرجات" من غير ما تلف على كل امتحان لوحده.
     * @return array<int,object>
     */
    public function forStudent($studentId): array
    {
        return DB::table('exam_attempts as a')
            ->join('exams as e', 'e.id', '=', 'a.exam_id')
            ->where('a.student_id', $studentId)
            ->select(
                'a.id', 'a.exam_id', 'a.attempt_number', 'a.status',
                'a.started_at', 'a.submitted_at', 'a.score', 'a.percentage',
                'e.title as exam_title', 'e.results_published_at', 'e.result_visibility', 'e.end_at'
            )
            ->orderByDesc('a.submitted_at')
            ->get()
            ->all();
    }

    /**
     * Round 4 — سطح المدرس: كل محاولات امتحان واحد (كل الطلاب)، مع اسم/رقم
     * الطالب عبر join (نفس نمط ExamTargetRepository::listMatching). الملكية
     * (إن الامتحان ده تابع للمدرس أصلاً) بتتفحص قبل ما الميثود دي تتنادى —
     * راجع ExamGradingService::listAttemptsForExam(). violations_count
     * مضاف من Round 5 (Phase 16) — عشان جدول محاولات المدرس يعرض عمود
     * المخالفات من غير أي query إضافي.
     * @return array<int,object>
     */
    public function forExamWithStudentInfo($examId): array
    {
        return DB::table('exam_attempts as a')
            ->join('students as s', 's.id', '=', 'a.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('a.exam_id', $examId)
            ->select(
                'a.id', 'a.student_id', 'a.attempt_number', 'a.status',
                'a.started_at', 'a.submitted_at', 'a.score', 'a.percentage', 'a.auto_submitted', 'a.violations_count',
                'a.cancelled_at', 'a.cancel_reason',
                'a.is_late', 'a.extra_time_minutes', 'a.late_penalty_percent', 'a.score_before_penalty',
                'a.identity_status', 'a.proctoring_flags_count', 'a.session_claims_count',
                's.student_number', 'u.id as user_id', 'u.full_name', 'u.email'
            )
            ->orderByDesc('a.submitted_at')
            ->get()
            ->all();
    }

    /** @return ExamAttempt[] كل المحاولات in_progress اللي وقتها عدى — للـ auto-submit sweep (command + lazy enforceTimer). */
    public function allExpiredInProgress(): array
    {
        // expires_at <= now هو الفلتر الخشن في الـ SQL، وبعدين بنستبعد اللي لسه جوه فترة السماح.
        return ExamAttempt::with('exam')
            ->whereIn('status', ExamAttempt::ACTIVE_STATUSES)
            ->where('expires_at', '<=', now())
            ->get()
            ->filter(fn (ExamAttempt $a) => $a->isHardExpired())
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // Answers
    // -----------------------------------------------------------------

    /** @return ExamAnswer[] كل إجابات المحاولة دي، keyed بـ exam_question_id. */
    public function answersForAttempt($attemptId): array
    {
        return ExamAnswer::where('exam_attempt_id', $attemptId)->get()->keyBy('exam_question_id')->all();
    }

    public function findAnswer($attemptId, $examQuestionId): ?ExamAnswer
    {
        return ExamAnswer::where('exam_attempt_id', $attemptId)->where('exam_question_id', $examQuestionId)->first();
    }

    /** Upsert — Phase 12 "Answer Created / Answer Updated" في عملية واحدة. */
    public function upsertAnswer($attemptId, $examQuestionId, array $data): ExamAnswer
    {
        $answer = $this->findAnswer($attemptId, $examQuestionId);
        $data['answered_at'] = now();

        if ($answer) {
            $answer->fill($data);
            $answer->save();
            return $answer;
        }

        return ExamAnswer::create(array_merge($data, [
            'exam_attempt_id'  => $attemptId,
            'exam_question_id' => $examQuestionId,
        ]));
    }

    /** Phase 12 "Answer Deleted" — بيرجع true لو فيه إجابة كانت موجودة فعلاً واتمسحت. */
    public function deleteAnswer($attemptId, $examQuestionId): bool
    {
        $answer = $this->findAnswer($attemptId, $examQuestionId);
        if (!$answer) {
            return false;
        }
        $answer->delete();
        return true;
    }

    // -----------------------------------------------------------------
    // Attempt-scoped questions (Round 7 — Phase 6/7) — بديل ExamRepository::
    // questionsFor()/findPivot() لكل كود المحاولات/التصحيح، عشان الأسئلة
    // ممكن تختلف من طالب لطالب تحت question pools.
    // -----------------------------------------------------------------

    /**
     * صفوف exam_questions اللي فعليًا تخص المحاولة دي بالذات، بترتيبها
     * الفعلي (exam_attempt_questions.sort_order — بعد أي خلط لو
     * randomize_questions=true)، مش ترتيب التأليف. الشكل نفس exam_questions
     * pivot تمامًا (->question, ->marks_override) عشان كل الكود اللي كان
     * بيستهلك questionsFor() القديمة يشتغل من غير تعديل في الشكل.
     * @return ExamQuestion[]
     */
    public function questionsForAttempt($attemptId): array
    {
        return ExamQuestion::with('question.options')
            ->join('exam_attempt_questions', 'exam_attempt_questions.exam_question_id', '=', 'exam_questions.id')
            ->where('exam_attempt_questions.exam_attempt_id', $attemptId)
            ->orderBy('exam_attempt_questions.sort_order')
            ->select('exam_questions.*')
            ->get()
            ->all();
    }

    /** عدد الأسئلة المخصصة لمحاولة واحدة (ممكن يختلف عن محاولة تانية على نفس الامتحان لو فيه pools). */
    public function questionCountForAttempt($attemptId): int
    {
        return ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->count();
    }

    /**
     * @return array<int,int> عدد الأسئلة لكل محاولة جوه امتحان واحد، keyed بـ attempt_id —
     * بديل "عدد أسئلة الامتحان الواحد" (كان ثابت لكل الطلاب قبل Round 7) لسطح المدرس.
     */
    public function questionCountsForExam($examId): array
    {
        return DB::table('exam_attempt_questions as eaq')
            ->join('exam_attempts as a', 'a.id', '=', 'eaq.exam_attempt_id')
            ->where('a.exam_id', $examId)
            ->select('eaq.exam_attempt_id', DB::raw('count(*) as cnt'))
            ->groupBy('eaq.exam_attempt_id')
            ->pluck('cnt', 'exam_attempt_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * بديل ExamRepository::findPivot() — بيتأكد إن الـ exam_question ده
     * فعلاً اتخصص للمحاولة دي بالذات (مش بس تابع لنفس الامتحان)، عشان
     * طالب ميقدرش يجاوب/يشوف سؤال طلع من pool لطالب تاني.
     */
    public function pivotForAttempt($attemptId, $examQuestionId): ?ExamQuestion
    {
        $exists = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)
            ->where('exam_question_id', $examQuestionId)
            ->exists();
        if (!$exists) {
            return null;
        }
        return ExamQuestion::with('question.options')->find($examQuestionId);
    }

    /**
     * بتتنادى مرة واحدة بس وقت startAttempt() (من QuestionSelectionService) —
     * بتخزن الترتيب الفعلي النهائي (بعد أي خلط) اللي الطالب هيشوفه، وبيفضل
     * ثابت طول عمر المحاولة.
     * @param int[] $orderedExamQuestionIds
     */
    public function createAttemptQuestions($attemptId, array $orderedExamQuestionIds): void
    {
        foreach ($orderedExamQuestionIds as $i => $examQuestionId) {
            ExamAttemptQuestion::create([
                'exam_attempt_id'  => $attemptId,
                'exam_question_id' => $examQuestionId,
                'sort_order'       => $i,
            ]);
        }
    }
}
