<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق موديل ProjectTeamMember القديم بالظبط (migration 089 + 131 + 134).
 * صف بيتملى بواحدة من طريقتين: دعوة إيميل بتتحل لحساب موجود (user_id +
 * invited_email، status='pending' لحد ما يتقبل)، أو إدخال يدوي بدون حساب
 * (member_name + academic_year + student_number، status='accepted' فورًا
 * — انظر isManual()).
 */
class ProjectTeamMember extends Model
{
    protected $table = 'project_team_members';

    protected $fillable = [
        'project_id', 'user_id', 'invited_email', 'member_name', 'academic_year',
        'student_number', 'role', 'status', 'invited_by', 'invited_at', 'responded_at',
    ];

    protected $casts = [
        'invited_at'   => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** عضو مُسجَّل يدويًا بدون حساب منصة، عكس الدعوة بالإيميل. */
    public function isManual(): bool
    {
        return $this->user_id === null && $this->invited_email === null;
    }

    /** يطابق ProjectTeamMember::toRowArray() القديمة بالظبط. display_name بيرجع لـ: اسم يدوي -> إيميل الدعوة -> "Team member". */
    public function toRowArray(): array
    {
        return [
            'id'             => $this->id,
            'display_name'   => $this->member_name ?: ($this->invited_email ?: 'Team member'),
            'is_manual'      => $this->isManual(),
            'academic_year'  => $this->academic_year,
            'student_number' => $this->student_number,
            'invited_email'  => $this->invited_email,
            'role'           => $this->role,
            'status'         => $this->status,
            'invited_by'     => $this->invited_by !== null ? (int) $this->invited_by : null,
        ];
    }
}
