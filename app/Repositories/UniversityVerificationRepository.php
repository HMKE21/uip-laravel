<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/UniversityVerificationRepository.php القديمة —
 * الداتا بتاعة `university_verification_requests` (migration 026). مفيش
 * موديل مخصص هنا برضو، لنفس السبب في القديمة: الصفوف دايمًا بتتقرا/تتكتب
 * كمجموعة خاصة بجامعة واحدة، فـ array عادي أبسط من Active Record هنا.
 */
class UniversityVerificationRepository
{
    /** @return array<int,array<string,mixed>> الأحدث الأول */
    public function forUniversity($universityId): array
    {
        return DB::table('university_verification_requests')
            ->where('university_id', $universityId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    public function create(array $data): array
    {
        $data['created_at'] = $data['created_at'] ?? now();
        $id = DB::table('university_verification_requests')->insertGetId($data);
        return array_merge($data, ['id' => $id]);
    }
}
