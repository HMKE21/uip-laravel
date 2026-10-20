<?php

namespace App\Repositories;

use App\Models\AcademicRank;

/**
 * منقولة من app/Repositories/AcademicRankRepository.php القديمة (Core\Model
 * -> Eloquent) — بند 10. availableFor() بترجع الرتب الافتراضية على مستوى
 * المنصة + رتب الجامعة الخاصة بيها، مستخدمة في فورم إسناد Academic Staff.
 */
class AcademicRankRepository
{
    public function find($id): ?AcademicRank
    {
        return AcademicRank::find($id);
    }

    /** @return AcademicRank[] رتب افتراضية على المنصة + رتب الجامعة دي الخاصة، الشغالة بس */
    public function availableFor($universityId): array
    {
        return AcademicRank::where('is_active', true)
            ->where(function ($q) use ($universityId) {
                $q->whereNull('university_id')->orWhere('university_id', $universityId);
            })
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function create(array $data): AcademicRank
    {
        return AcademicRank::create($data);
    }
}
