<?php

namespace App\Repositories;

use App\Models\QuestionBank;

/**
 * Exam & Assessment System — Round 1. findOwned() هي lookup مقيّدة
 * الملكية اللي كل تعديل/حذف/قراءة-تفصيلية للبنك المفروض يعدي عليها —
 * عضو هيئة تدريس تاني عمره ما يقدر يلمس بنك أسئلة مش بتاعه، حتى لو عرف
 * الـ id (نفس نمط StudentGroupRepository::findOwned()).
 */
class QuestionBankRepository
{
    public function find($id): ?QuestionBank
    {
        return QuestionBank::find($id);
    }

    /** Ownership-checked lookup — مقيدة بـ created_by_academic_staff_id بتاع العضو نفسه. */
    public function findOwned($id, $academicStaffId): ?QuestionBank
    {
        $bank = QuestionBank::find($id);
        if (!$bank || (int) $bank->created_by_academic_staff_id !== (int) $academicStaffId) {
            return null;
        }
        return $bank;
    }

    /** @return QuestionBank[] كل بنوك الأسئلة اللي عضو هيئة التدريس ده أنشأها. */
    public function forCreator($academicStaffId): array
    {
        return QuestionBank::where('created_by_academic_staff_id', $academicStaffId)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function create(array $data): QuestionBank
    {
        return QuestionBank::create($data);
    }

    public function countForCreator($academicStaffId): int
    {
        return QuestionBank::where('created_by_academic_staff_id', $academicStaffId)->count();
    }
}
