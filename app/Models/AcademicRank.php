<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/AcademicRank.php القديمة — بند 10 (Academic Staff +
 * Faculty portal). يطابق جدول `academic_ranks` (migration 100). نظام
 * مسميات/رتب مرن غير ثابت (Professor, Lecturer, Teaching Assistant, Dean,
 * Head of Department, University President, ...). university_id = NULL
 * يعني رتبة افتراضية على مستوى المنصة متاحة لكل الجامعات؛ الجامعة تقدر
 * كمان تعرّف رتبها الخاصة. تستخدمها App\Models\AcademicStaff.
 */
class AcademicRank extends Model
{
    protected $table = 'academic_ranks';

    protected $fillable = [
        'university_id', 'name_ar', 'name_en', 'category', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function isPlatformDefault(): bool
    {
        return $this->university_id === null;
    }

    public function name(string $locale = 'en'): string
    {
        return $locale === 'ar'
            ? ($this->name_ar ?: $this->name_en)
            : ($this->name_en ?: $this->name_ar);
    }
}
