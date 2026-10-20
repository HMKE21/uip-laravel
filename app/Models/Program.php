<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `programs` القديم بالظبط (migration 100 + is_public لاحقًا).
 * تابع لقسم واحد (department_id).
 */
class Program extends Model
{
    protected $table = 'programs';

    protected $fillable = [
        'department_id', 'name_ar', 'name_en', 'code', 'description',
        'degree_type', 'duration_years', 'status', 'is_public',
    ];

    protected $casts = [
        'is_public'      => 'boolean',
        'duration_years' => 'float',
    ];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function name(string $locale = 'en'): string
    {
        return $locale === 'ar'
            ? ($this->name_ar ?: $this->name_en)
            : ($this->name_en ?: $this->name_ar);
    }
}
