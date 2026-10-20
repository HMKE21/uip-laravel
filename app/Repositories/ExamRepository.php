<?php

namespace App\Repositories;

use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamQuestionPool;

/**
 * Exam & Assessment System — Round 1، موسّعة في Round 7. findOwned() هي
 * lookup مقيّدة الملكية اللي كل تعديل/حذف للامتحان المفروض يعدي عليها
 * (نفس نمط QuestionBankRepository::findOwned()). عمليات الـ pivot
 * (attach/detach/reorder سؤال) هنا كمان — مربوطة دايمًا بـ exam_id اللي
 * الـ service أكد ملكيته أول حاجة.
 *
 * Round 7: إعدادات question pools على الامتحان (exam_question_pools) +
 * findOrCreateQuestionForPool() — نقطة الدخول الوحيدة اللي بتنشئ صف
 * exam_questions من سحب pool، وبتعمل dedup (نفس السؤال اتسحب قبل كده
 * لطالب تاني على نفس الامتحان -> بيتشارك نفس الصف، مبيتكررش).
 */
class ExamRepository
{
    public function find($id): ?Exam
    {
        return Exam::find($id);
    }

    /** Ownership-checked lookup — مقيدة بـ created_by_academic_staff_id بتاع العضو نفسه. */
    public function findOwned($id, $academicStaffId): ?Exam
    {
        $exam = Exam::find($id);
        if (!$exam || (int) $exam->created_by_academic_staff_id !== (int) $academicStaffId) {
            return null;
        }
        return $exam;
    }

    /** @return Exam[] كل الامتحانات اللي عضو هيئة التدريس ده أنشأها. */
    public function forCreator($academicStaffId): array
    {
        return Exam::where('created_by_academic_staff_id', $academicStaffId)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function create(array $data): Exam
    {
        return Exam::create($data);
    }

    public function countForCreator($academicStaffId): int
    {
        return Exam::where('created_by_academic_staff_id', $academicStaffId)->count();
    }

    /** صفوف pivot امتحان واحد، مرتبة sort_order، مع السؤال محمّل معاها. */
    public function questionsFor($examId): array
    {
        return ExamQuestion::with('question.options')
            ->where('exam_id', $examId)
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function findPivot($examId, $examQuestionId): ?ExamQuestion
    {
        return ExamQuestion::where('id', $examQuestionId)->where('exam_id', $examId)->first();
    }

    public function pivotExists($examId, $questionId): bool
    {
        return ExamQuestion::where('exam_id', $examId)->where('question_id', $questionId)->exists();
    }

    public function nextSortOrder($examId): int
    {
        return (int) (ExamQuestion::where('exam_id', $examId)->max('sort_order') ?? -1) + 1;
    }

    public function attachQuestion($examId, $questionId, ?float $marksOverride, int $sortOrder): ExamQuestion
    {
        return ExamQuestion::create([
            'exam_id'        => $examId,
            'question_id'    => $questionId,
            'marks_override' => $marksOverride,
            'sort_order'     => $sortOrder,
        ]);
    }

    public function detachQuestion($examId, $examQuestionId): bool
    {
        $pivot = $this->findPivot($examId, $examQuestionId);
        if (!$pivot) {
            return false;
        }
        $pivot->delete();
        return true;
    }

    public function setMarksOverride($examId, $examQuestionId, float $marks): bool
    {
        $pivot = $this->findPivot($examId, $examQuestionId);
        if (!$pivot) {
            return false;
        }
        $pivot->marks_override = round($marks, 2);
        $pivot->save();
        return true;
    }

    public function reorder($examId, array $orderedExamQuestionIds): void
    {
        foreach ($orderedExamQuestionIds as $i => $examQuestionId) {
            ExamQuestion::where('id', $examQuestionId)->where('exam_id', $examId)->update(['sort_order' => $i]);
        }
    }

    /** @return ExamQuestion[] صفوف exam_questions اللي source=manual بس (المتضافة يدويًا، Round 1). */
    public function manualQuestionsFor($examId): array
    {
        return ExamQuestion::with('question.options')
            ->where('exam_id', $examId)
            ->where('source', 'manual')
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    /**
     * Round 7 — نقطة الدخول الوحيدة اللي بتنشئ صف exam_questions من سحب
     * pool. لو السؤال ده اتسحب قبل كده لطالب تاني على نفس الامتحان (من
     * أي pool، أو حتى لو كان متضاف يدويًا أصلاً) بيرجّع نفس الصف الموجود
     * من غير تعديل — أول مرة السؤال يظهر هي اللي بتحدد marks_override
     * بتاعه (first-come-first-served، موثّق في docblock migration
     * 2026_08_28_210000).
     */
    public function findOrCreateQuestionForPool($examId, $questionId, $poolId, float $marksPerQuestion): ExamQuestion
    {
        $existing = ExamQuestion::where('exam_id', $examId)->where('question_id', $questionId)->first();
        if ($existing) {
            return $existing;
        }

        return ExamQuestion::create([
            'exam_id'          => $examId,
            'question_id'      => $questionId,
            'marks_override'   => $marksPerQuestion,
            'sort_order'       => $this->nextSortOrder($examId),
            'source'           => 'pool',
            'question_pool_id' => $poolId,
        ]);
    }

    // -----------------------------------------------------------------
    // Question Pool configs on an exam (exam_question_pools) — Round 7 Phase 6
    // -----------------------------------------------------------------

    /** @return ExamQuestionPool[] كل إعدادات الـ pools على امتحان واحد، مع الـ pool نفسه محمّل. */
    public function poolConfigsFor($examId): array
    {
        return ExamQuestionPool::with('pool')
            ->where('exam_id', $examId)
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function findPoolConfig($examId, $configId): ?ExamQuestionPool
    {
        return ExamQuestionPool::where('id', $configId)->where('exam_id', $examId)->first();
    }

    public function poolConfigExists($examId, $poolId): bool
    {
        return ExamQuestionPool::where('exam_id', $examId)->where('question_pool_id', $poolId)->exists();
    }

    public function nextPoolSortOrder($examId): int
    {
        return (int) (ExamQuestionPool::where('exam_id', $examId)->max('sort_order') ?? -1) + 1;
    }

    public function attachPoolConfig($examId, $poolId, array $data): ExamQuestionPool
    {
        return ExamQuestionPool::create(array_merge($data, [
            'exam_id'          => $examId,
            'question_pool_id' => $poolId,
            'sort_order'       => $this->nextPoolSortOrder($examId),
        ]));
    }

    public function updatePoolConfig(ExamQuestionPool $config, array $data): ExamQuestionPool
    {
        $config->fill($data);
        $config->save();
        return $config;
    }

    public function detachPoolConfig($examId, $configId): bool
    {
        $config = $this->findPoolConfig($examId, $configId);
        if (!$config) {
            return false;
        }
        $config->delete();
        return true;
    }

    // -----------------------------------------------------------------
    // Round 8 (Phase 26 — University/College Results). سطح قراءة إضافي
    // لأدوار faculty (كلية) وuniversity — منفصل عن forCreator() (عضو
    // هيئة التدريس بتاعه) لأن هنا الملكية مش بمقاس "أنا اللي عملته" —
    // نطاق أوسع (كل امتحانات كلية/جامعة كاملة، راجع docblock
    // ExamAnalyticsApiController للـ RBAC الفعلي).
    // -----------------------------------------------------------------

    /** @return Exam[] كل امتحانات كلية واحدة (faculty_id) — بغض النظر عن مين عملها من أعضاء الكلية. */
    public function forFaculty($facultyId): array
    {
        return Exam::where('faculty_id', $facultyId)->orderByDesc('id')->get()->all();
    }

    /** @return Exam[] كل امتحانات جامعة واحدة بالكامل (كل الكليات). */
    public function forUniversityScope($universityId): array
    {
        return Exam::where('university_id', $universityId)->orderByDesc('id')->get()->all();
    }

    // -----------------------------------------------------------------
    // Round 8 (Phase 29 — Notifications). سطح قراءة نظام-كامل (مش مقيد
    // بمدرس/كلية/جامعة بعينها) لصالح exams:notify-upcoming command —
    // الكرون بيدور على كل الامتحانات "starts soon"/"deadline approaching"
    // في المنصة كلها مرة واحدة، مش لكل مدرس لوحده.
    // -----------------------------------------------------------------

    /** @return Exam[] امتحانات scheduled هتبدأ خلال الشباك الزمني ده (starts soon). */
    public function scheduledStartingBetween($from, $to): array
    {
        return Exam::where('status', 'scheduled')
            ->whereBetween('start_at', [$from, $to])
            ->get()
            ->all();
    }

    /** @return Exam[] امتحانات published هتقفل خلال الشباك الزمني ده (deadline approaching). */
    public function publishedEndingBetween($from, $to): array
    {
        return Exam::where('status', 'published')
            ->whereNotNull('end_at')
            ->whereBetween('end_at', [$from, $to])
            ->get()
            ->all();
    }

    /**
     * @return Exam[] امتحانات لسه ما بدأتش وهتفتح بين $from و $to. status بيفضل 'scheduled' (أو 'published') لحد ما
     * الامتحان يخلص — مفيش حاجة بتقلبه وقت start_at — فبنبص على الاتنين.
     */
    public function opensBetween($from, $to): array
    {
        return Exam::whereIn('status', ['scheduled', 'published'])
            ->whereNotNull('start_at')
            ->where('start_at', '>', $from)
            ->where('start_at', '<=', $to)
            ->get()->all();
    }

    /** @return Exam[] امتحانات مفتوحة فعلًا (بدأت) وهتقفل بين $from و $to. */
    public function closesBetween($from, $to): array
    {
        return Exam::whereIn('status', ['scheduled', 'published', 'active'])
            ->whereNotNull('end_at')
            ->where('end_at', '>', $from)
            ->where('end_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $from))
            ->get()->all();
    }
}
