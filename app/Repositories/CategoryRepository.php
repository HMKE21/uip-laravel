<?php

namespace App\Repositories;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * منقولة جزئيًا من app/Repositories/CategoryRepository.php القديمة —
 * find()/active() (بند 4، فورم إنشاء/تعديل المشروع) + withPublishedCounts()
 * (بند 11 مرحلة 3 — شريحة "تصفح حسب التصنيف" في PublicApiController::
 * projects()). withPublishedCountsScoped() (صفحات الوحدة الأكاديمية
 * العامة — spec §14) وإدارة الأدمن (create/update) لسه هتيجي مع بندها.
 */
class CategoryRepository
{
    public function find($id): ?Category
    {
        return Category::find($id);
    }

    public function findBySlug(string $slug): ?Category
    {
        return Category::where('slug', $slug)->first();
    }

    /** @return Category[] كل تصنيف نشط، بترتيب العرض المنسّق. */
    public function active(): array
    {
        return Category::where('is_active', 1)->orderBy('sort_order')->orderBy('name_en')->get()->all();
    }

    /**
     * كل تصنيف نشط + عدد المشاريع *المنشورة* الحاملة له — شريحة "تصفح
     * حسب التصنيف" في PublicApiController::projects(). تصنيف بعدد صفر
     * لسه بيترجع (total: 0) بدل ما يختفي، عشان تصنيف جديد يفضل ظاهر قبل
     * أول مشروع يتنشر بيه — يطابق withPublishedCounts() القديمة بالظبط.
     * @return array<int,array{id:int,slug:string,name_en:string,name_ar:string,icon:?string,total:int}>
     */
    public function withPublishedCounts(): array
    {
        return DB::table('categories as c')
            ->leftJoin('projects as p', function ($join) {
                $join->on('p.category_id', '=', 'c.id')->where('p.status', 'published');
            })
            ->where('c.is_active', 1)
            ->select('c.id', 'c.slug', 'c.name_en', 'c.name_ar', 'c.icon')
            ->selectRaw('COUNT(p.id) as total')
            ->groupBy('c.id', 'c.slug', 'c.name_en', 'c.name_ar', 'c.icon')
            ->orderBy('c.sort_order')
            ->orderBy('c.name_en')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }
}
