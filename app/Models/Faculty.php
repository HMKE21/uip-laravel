<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `faculties` القديم بالظبط (migration 100 + 106 user_id).
 * تابعة لجامعة واحدة (university_id). جزء من التسلسل الهرمي
 * University -> Faculty -> Department.
 */
class Faculty extends Model
{
    protected $table = 'faculties';

    protected $fillable = [
        'university_id', 'user_id', 'name_ar', 'name_en', 'slug', 'description',
        'mission', 'vision', 'logo_path', 'cover_path', 'website',
        'contact_email', 'contact_phone', 'location', 'status', 'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
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
