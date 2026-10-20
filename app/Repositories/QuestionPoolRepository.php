<?php

namespace App\Repositories;

use App\Models\QuestionPool;

/**
 * Exam & Assessment System — Round 7 (Phase 6). findOwned() هنا مقيدة
 * بملكية البنك اللي الـ pool تابع له (question_bank_id -> created_by_
 * academic_staff_id) — نفس نمط QuestionRepository::findOwned() بالظبط
 * (السؤال بيتحقق ملكيته عبر بنكه، الـ pool هنا بيتحقق نفس الطريقة).
 * syncQuestions() بتعمل استبدال كامل للعضوية (زي ExamRubricRepository::
 * upsertRubric() — مفيش partial add/remove من هنا، القائمة كاملة بتتبعت).
 */
class QuestionPoolRepository
{
    public function find($id): ?QuestionPool
    {
        return QuestionPool::find($id);
    }

    /** Ownership-checked lookup — الـ pool لازم يكون تابع لبنك واحد من بنوك المستخدم نفسه. */
    public function findOwned($id, $academicStaffId): ?QuestionPool
    {
        $pool = QuestionPool::with('bank')->find($id);
        if (!$pool || !$pool->bank || (int) $pool->bank->created_by_academic_staff_id !== (int) $academicStaffId) {
            return null;
        }
        return $pool;
    }

    /** @return QuestionPool[] كل الـ pools التابعة لبنك واحد. */
    public function forBank($bankId): array
    {
        return QuestionPool::withCount('questions')
            ->where('question_bank_id', $bankId)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function create(array $data): QuestionPool
    {
        return QuestionPool::create($data);
    }

    public function update(QuestionPool $pool, array $data): QuestionPool
    {
        $pool->fill($data);
        $pool->save();
        return $pool;
    }

    public function delete(QuestionPool $pool): void
    {
        $pool->delete();
    }

    /** استبدال كامل لعضوية الـ pool — نفس فلسفة ExamRubricRepository::upsertRubric(). */
    public function syncQuestions(QuestionPool $pool, array $questionIds): void
    {
        $pool->questions()->sync(array_values(array_unique(array_map('intval', $questionIds))));
    }

    public function questionCount($poolId): int
    {
        return QuestionPool::find($poolId)?->questions()->count() ?? 0;
    }

    /**
     * كل أسئلة الـ pool (id/difficulty/topic) — الشكل الخفيف ده هو اللي
     * QuestionSelectionService::draw() بيشتغل عليه (مش محتاج كل عمود في
     * questions، بس اللي هيفلتر/يوزّع بيه).
     * @return array<int,object{id:int,difficulty:string,topic:?string}>
     */
    public function memberQuestionsLight($poolId): array
    {
        return QuestionPool::find($poolId)
            ?->questions()
            ->where('questions.status', 'active')
            ->get(['questions.id', 'questions.difficulty', 'questions.topic'])
            ->all() ?? [];
    }
}
