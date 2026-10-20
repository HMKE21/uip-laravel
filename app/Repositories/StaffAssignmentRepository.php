<?php

namespace App\Repositories;

use App\Models\StaffAssignment;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/StaffAssignmentRepository.php القديمة
 * (Core\Database -> DB facade + Eloquent) — بند 10. assign() هو مسار
 * الكتابة الوحيد اللي أي كنترولر مستقبلي المفروض ينادي عليه: عمرها ما
 * بتكتب فوق صف — بتقفل أي صف شغال حاليًا لنفس النطاق+الرتبة بالظبط
 * (end_date + is_active=0) وبعدين تضيف صف جديد، كل ده جوه transaction
 * واحدة — زي ما الـ spec طالب: "متكتبش فوق تاريخ القيادة القديم".
 */
class StaffAssignmentRepository
{
    public function find($id): ?StaffAssignment
    {
        return StaffAssignment::find($id);
    }

    /**
     * الحامل (الحاملين) الحاليين لرتبة إدارية معينة لنطاق واحد (مثلاً "مين
     * عميد الكلية رقم 4 دلوقتي"). عادةً صف واحد، بس رتب زي Vice Dean ممكن
     * شرعيًا يكون ليها أكتر من حامل شغال في نفس الوقت، فبترجع array.
     * @return StaffAssignment[]
     */
    public function currentFor(string $scopeType, $scopeId, $academicRankId): array
    {
        return StaffAssignment::where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('academic_rank_id', $academicRankId)
            ->where('is_active', true)
            ->get()
            ->all();
    }

    /** تاريخ الإسناد الكامل (الحالي + السابق) لنطاق واحد، الأحدث أولًا. */
    public function historyForScope(string $scopeType, $scopeId): array
    {
        return StaffAssignment::where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->orderByDesc('start_date')
            ->get()
            ->all();
    }

    /** تاريخ الإسناد الكامل لعضو هيئة تدريس واحد عبر كل النطاقات اللي شغلها. */
    public function historyForStaff($academicStaffId): array
    {
        return StaffAssignment::where('academic_staff_id', $academicStaffId)
            ->orderByDesc('start_date')
            ->get()
            ->all();
    }

    /**
     * بتسند $academicStaffId لرتبة $academicRankId على نطاق
     * $scopeType/$scopeId ابتداءً من $startDate (افتراضيًا النهاردة).
     * بتقفل أول أي إسناد شغال حاليًا لنفس النطاق+الرتبة (end_date =
     * اليوم قبل بداية الجديد، is_active=0) عشان التاريخ عمره ما يضيع أو
     * يتكتب فوقه، وبعدين تضيف الصف الجديد الشغال. كل ده جوه transaction
     * واحدة عشان انقطاع نص الطريق مايسيبش أكتر من "شغال" واحد لمنصب فردي
     * زي العميد.
     */
    public function assign(
        $academicStaffId,
        $academicRankId,
        string $scopeType,
        $scopeId,
        ?string $startDate = null,
        $assignedBy = null,
        ?string $notes = null
    ): StaffAssignment {
        $startDate = $startDate ?? now()->toDateString();

        return DB::transaction(function () use ($academicStaffId, $academicRankId, $scopeType, $scopeId, $startDate, $assignedBy, $notes) {
            $current = $this->currentFor($scopeType, $scopeId, $academicRankId);
            $dayBefore = date('Y-m-d', strtotime($startDate . ' -1 day'));
            foreach ($current as $assignment) {
                $assignment->end_date = $dayBefore;
                $assignment->is_active = false;
                $assignment->save();
            }

            return StaffAssignment::create([
                'academic_staff_id' => $academicStaffId,
                'academic_rank_id'  => $academicRankId,
                'scope_type'        => $scopeType,
                'scope_id'          => $scopeId,
                'start_date'        => $startDate,
                'end_date'          => null,
                'is_active'         => true,
                'assigned_by'       => $assignedBy,
                'notes'             => $notes,
            ]);
        });
    }

    /** بتنهي إسناد واحد بعينه (مثلاً استقالة) من غير ما تستبدله بحامل جديد. */
    public function endAssignment($assignmentId, ?string $endDate = null): bool
    {
        $assignment = StaffAssignment::find($assignmentId);
        if (!$assignment) {
            return false;
        }
        $assignment->end_date = $endDate ?? now()->toDateString();
        $assignment->is_active = false;
        return $assignment->save();
    }

    /**
     * الحامل الحالي لمسمى إداري معين على نطاق واحد (مثلاً "مين رئيس قسم
     * رقم 7 دلوقتي") — بالمطابقة على academic_ranks.name_en، نفس الاتفاق
     * النصي اللي كل إشارة تانية للمسميات دي في المشروع بتستخدمه.
     * @return array{user_id:int,full_name:string,email:string}|null
     */
    public function currentHolderByRankName(string $scopeType, $scopeId, string $rankNameEn): ?array
    {
        $rows = DB::select(
            "SELECT ast.user_id, u.full_name, u.email
             FROM staff_assignments sa
             INNER JOIN academic_staff ast ON ast.id = sa.academic_staff_id
             INNER JOIN users u ON u.id = ast.user_id
             INNER JOIN academic_ranks r ON r.id = sa.academic_rank_id
             WHERE sa.scope_type = ?
               AND sa.scope_id = ?
               AND sa.is_active = 1
               AND sa.end_date IS NULL
               AND r.name_en = ?
             ORDER BY sa.start_date DESC
             LIMIT 1",
            [$scopeType, $scopeId, $rankNameEn]
        );
        return $rows[0] ?? null;
    }
}
