<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * منقولة جزئيًا من app/Repositories/UniversityReverificationLogRepository.php
 * القديمة — forUniversity() بس (اللي صفحة Verification بتاعة الجامعة
 * محتاجاها لسجلّها الشخصي). expired()/dueForExpiryNotice() خاصين
 * بـ cron إعادة التحقق التلقائي (094) — هيتضافوا لما بند الـ cron/الأدمن
 * الخاص بيهم ييجي، مش استهلاك مباشر من بند 5.
 */
class UniversityReverificationLogRepository
{
    /** الأحدث الأول، لسجلّ صفحة Verification بتاعة الجامعة نفسها. @return array<int,array<string,mixed>> */
    public function forUniversity($universityId, int $limit = 50): array
    {
        return DB::table('university_reverification_log')
            ->where('university_id', $universityId)
            ->orderByDesc('created_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }
}
