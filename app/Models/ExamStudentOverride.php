<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * استثناء لطالب واحد على امتحان واحد (إعادة الامتحان بصلاحية المدرس) —
 * راجع ExamAttemptManagementService. extra_attempts بتتضاف فوق
 * exams.max_attempts، و available_until (اختياري) بيفتح نافذة وصول خاصة
 * للطالب ده بس بعد ما نافذة الامتحان العامة تخلص.
 */
class ExamStudentOverride extends Model
{
    protected $table = 'exam_student_overrides';

    protected $fillable = ['exam_id', 'student_id', 'extra_attempts', 'available_until', 'granted_by', 'reason', 'proctoring_waived'];

    protected $casts = [
        'available_until' => 'datetime',
        'extra_attempts'  => 'integer',
        'proctoring_waived' => 'boolean',
    ];

    /** النافذة الخاصة مفتوحة لو مفيش available_until (يعني مفيش تقييد زمني إضافي) أو لسه ماعدّاش. */
    public function windowIsOpen(): bool
    {
        return $this->available_until === null || now()->lessThanOrEqualTo($this->available_until);
    }
}
