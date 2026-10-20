<?php

namespace App\Repositories;

use App\Models\ExamSecurityEvent;

/**
 * Exam & Assessment System — Round 5 (Phase 16). كل استعلامات
 * exam_security_events من هنا — نفس نمط باقي الـ repos في المشروع ده
 * (كلاس رفيع، المنطق كله في الخدمة).
 */
class ExamSecurityEventRepository
{
    public function create(array $data): ExamSecurityEvent
    {
        return ExamSecurityEvent::create($data);
    }

    /** @return ExamSecurityEvent[] كل أحداث محاولة واحدة، الأقدم أولاً (timeline). */
    public function forAttempt($attemptId): array
    {
        return ExamSecurityEvent::where('exam_attempt_id', $attemptId)
            ->orderBy('occurred_at')
            ->get()
            ->all();
    }

    /** عدد الأحداث اللي اتحسبت "مخالفة" فعليًا لمحاولة واحدة — مرجع مستقل عن exam_attempts.violations_count نفسه (بيتطابق معاه، بس مفيد لأي تدقيق/audit). */
    public function violationCountForAttempt($attemptId): int
    {
        return ExamSecurityEvent::where('exam_attempt_id', $attemptId)->where('is_violation', true)->count();
    }
}
