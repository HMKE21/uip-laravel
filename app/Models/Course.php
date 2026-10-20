<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $table = 'courses';

    protected $fillable = [
        'university_id', 'faculty_id', 'department_id', 'code', 'name_en', 'name_ar', 'description',
        'credit_hours', 'academic_year', 'semester', 'status', 'created_by_user_id',
    ];

    public function name(string $locale = 'en'): string
    {
        return $locale === 'ar' ? ($this->name_ar ?: $this->name_en) : ($this->name_en ?: (string) $this->name_ar);
    }
}
