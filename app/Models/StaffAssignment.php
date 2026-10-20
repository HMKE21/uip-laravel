<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/StaffAssignment.php القديمة — بند 10. يطابق جدول
 * `staff_assignments` (migration 101). إسناد قيادي محفوظ بالتاريخ (Dean،
 * Vice Dean، Head of Department، University President، ...). صف بـ
 * end_date = NULL و is_active = 1 هو الحامل الحالي للمنصب ده على النطاق
 * ده؛ إنهاء إسناد بيحط end_date + is_active = 0 — الصفوف عمرها ما بتتمسح
 * أو تتكتب فوقها، فالتاريخ الكامل يفضل queryable (شوف
 * StaffAssignmentRepository::endAssignment()).
 */
class StaffAssignment extends Model
{
    protected $table = 'staff_assignments';

    protected $fillable = [
        'academic_staff_id', 'academic_rank_id', 'scope_type', 'scope_id',
        'start_date', 'end_date', 'is_active', 'assigned_by', 'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_active'  => 'boolean',
    ];

    public function isCurrent(): bool
    {
        return (bool) $this->is_active && $this->end_date === null;
    }
}
