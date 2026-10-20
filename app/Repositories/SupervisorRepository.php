<?php

namespace App\Repositories;

use App\Models\Supervisor;

/**
 * منقولة من app/Repositories/SupervisorRepository.php القديمة (Core\Model
 * -> Eloquent) — بند 9 (Supervisors). findByUserId()/permissionsForUser()/
 * markAcceptedIfPending() هي اللي بتحل لوجين المشرف نفسه لصف الروستر
 * بتاعه وصلاحياته.
 */
class SupervisorRepository
{
    /** @return Supervisor[] */
    public function forUniversity($universityId): array
    {
        return Supervisor::where('university_id', $universityId)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    public function findOwned($id, $universityId): ?Supervisor
    {
        $supervisor = Supervisor::find($id);
        return ($supervisor && (int) $supervisor->university_id === (int) $universityId) ? $supervisor : null;
    }

    public function find($id): ?Supervisor
    {
        return Supervisor::find($id);
    }

    public function findByEmail($universityId, string $email): ?Supervisor
    {
        return Supervisor::where('university_id', $universityId)->where('email', $email)->first();
    }

    /** صف الروستر بتاع لوجين المشرف نفسه. */
    public function findByUserId($userId): ?Supervisor
    {
        return Supervisor::where('user_id', $userId)->first();
    }

    /** زي findByUserId() بس بس لو حسابه شغال. */
    public function findActiveByUserId($userId): ?Supervisor
    {
        $supervisor = $this->findByUserId($userId);
        return ($supervisor && $supervisor->status === 'active') ? $supervisor : null;
    }

    /**
     * صلاحيات لوجين المشرف نفسه. على عكس أصحاب الحسابات الأصليين
     * (اللي صاحب الحساب الأصلي وصوله كامل)، كل حساب /supervisor/* هو صف
     * روستر — مفيش حالة "صاحب حساب غير مقيّد" هنا — فبترجع [] مش null
     * لمستخدم غير معروف، والمستدعي المفروض يعامل مشرف مفقود/غير شغال
     * على إنه صفر صلاحيات مش وصول كامل.
     * @return string[]
     */
    public function permissionsForUser($userId): array
    {
        $supervisor = $this->findActiveByUserId($userId);
        if (!$supervisor) {
            return [];
        }
        return $supervisor->permissions !== '' && $supervisor->permissions !== null
            ? explode(',', $supervisor->permissions)
            : [];
    }

    public function create(array $data): Supervisor
    {
        return Supervisor::create($data);
    }

    public function countByStatus($universityId, string $status): int
    {
        return Supervisor::where('university_id', $universityId)->where('status', $status)->count();
    }

    /**
     * أسماء المشرفين الشغالين بتوع الجامعة دي — بتغذي autocomplete
     * المشرف النصي الحر في إنشاء/تعديل مشروع الطالب.
     * @return string[]
     */
    public function activeNamesForUniversity($universityId): array
    {
        return Supervisor::where('university_id', $universityId)
            ->where('status', 'active')
            ->orderBy('full_name')
            ->pluck('full_name')
            ->all();
    }

    /**
     * بتحدد دعوة pending كـ accepted أول ما المشرف يعمل أول لوجين له —
     * بتتنادى من LoginController فور نجاح تسجيل الدخول.
     */
    public function markAcceptedIfPending($userId): void
    {
        $supervisor = $this->findByUserId($userId);
        if ($supervisor && $supervisor->invitation_status === 'pending') {
            $supervisor->fill(['invitation_status' => 'accepted', 'accepted_at' => now()]);
            $supervisor->save();
        }
    }
}
