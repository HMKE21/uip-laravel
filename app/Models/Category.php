<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق موديل Category القديم بالظبط (migration 138) — التصنيف الحقيقي
 * ثنائي اللغة اللي بيحل محل عمود `projects.category` النصي الحر اللي
 * فورم الإنشاء/التعديل كان بيكتب فيه مباشرة. `projects.category_id` هو
 * الـ FK؛ عمود `category` النصي القديم فاضل موجود لأي قارئ لسه ماتحولش،
 * زي ما `project_links` سابت repository_url/demo_url من غير ما تتمسح
 * (migration 132).
 */
class Category extends Model
{
    protected $table = 'categories';

    protected $fillable = [
        'slug', 'name_en', 'name_ar', 'icon', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function name(string $locale = 'en'): string
    {
        return $locale === 'ar'
            ? ($this->name_ar ?: $this->name_en)
            : ($this->name_en ?: $this->name_ar);
    }
}
