<?php

namespace App\Repositories;

use App\Models\Question;
use App\Models\QuestionOption;

/**
 * Exam & Assessment System — Round 1. الملكية بتتفحص عبر بنك الأسئلة
 * (question_bank_id -> created_by_academic_staff_id)، مش عمود مباشر
 * على questions — نفس تصميم الـ migration (راجع docblock بتاعها).
 * الـ service layer هو اللي بيمرر البنك المتأكد ملكيته أصلًا هنا (عبر
 * QuestionBankRepository::findOwned() الأول)، فمفيش داعي لـ join إضافي
 * جوه كل ميثود هنا.
 */
class QuestionRepository
{
    public function find($id): ?Question
    {
        return Question::find($id);
    }

    /** Ownership-checked lookup — السؤال لازم يكون تابع لبنك واحد من بنوك المستخدم نفسه. */
    public function findOwned($id, $academicStaffId): ?Question
    {
        $question = Question::with('bank')->find($id);
        if (!$question || !$question->bank || (int) $question->bank->created_by_academic_staff_id !== (int) $academicStaffId) {
            return null;
        }
        return $question;
    }

    /** @return Question[] كل أسئلة بنك واحد، مرتبة الأحدث الأول. */
    public function forBank($bankId): array
    {
        return Question::where('question_bank_id', $bankId)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function create(array $data): Question
    {
        return Question::create($data);
    }

    public function replaceOptions($questionId, array $options): void
    {
        QuestionOption::where('question_id', $questionId)->delete();
        foreach ($options as $i => $opt) {
            QuestionOption::create([
                'question_id' => $questionId,
                'option_text' => $opt['option_text'],
                'is_correct'  => (bool) ($opt['is_correct'] ?? false),
                'sort_order'  => $i,
            ]);
        }
    }

    public function countForBank($bankId): int
    {
        return Question::where('question_bank_id', $bankId)->count();
    }
}
