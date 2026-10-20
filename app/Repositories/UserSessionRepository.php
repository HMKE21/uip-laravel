<?php

namespace App\Repositories;

use App\Models\UserSession;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/UserSessionRepository.php القديمة — بند 25
 * batch 1 (Security Portal — Sessions). بس الميثودز اللي فعلًا وراها
 * استعمال في SecuritySessionsApiController (activeWithUser/countActive/
 * countDistinctActiveUsers/find/revoke) — record()/touchActivity()/
 * deactivate()/activeForUser()/revokeOwn() القديمة (بتاعة AuthService
 * وقت اللوجين/اللوجاوت + self-service sessions) مش منقولة هنا عمدًا:
 * AuthService الحالي في اللارافيل مش بيكتب على `user_sessions` أصلًا
 * لسه (نفس الفجوة الموثّقة في SecurityLogRepository)، فمفيش استهلاك
 * حقيقي ليها دلوقتي — هتتضاف لما توصيل تسجيل الجلسة جوه AuthService
 * يتعمل فعليًا، مش قبل كده.
 */
class UserSessionRepository
{
    /** @return array<int,array<string,mixed>> جلسات نشطة، الأحدث نشاطًا الأول، مدموجة مع اسم/إيميل/دور المستخدم */
    public function activeWithUser(int $limit = 200): array
    {
        return DB::table('user_sessions as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->selectRaw('s.*, u.full_name, u.email,
                    (SELECT r.slug FROM roles r
                       INNER JOIN user_roles ur ON ur.role_id = r.id
                      WHERE ur.user_id = u.id ORDER BY ur.assigned_at ASC LIMIT 1) AS role_slug')
            ->where('s.is_active', 1)
            ->orderByDesc('s.last_activity_at')
            ->limit(max(1, $limit))
            ->get()
            // device_hash بصمة داخلية للتحقق من "نفس المتصفح" — مبتتعرضش في بورتال الأمان.
            ->map(function ($r) {
                $row = (array) $r;
                unset($row['device_hash']);
                return $row;
            })->all();
    }

    public function countActive(): int
    {
        return UserSession::where('is_active', 1)->count();
    }

    public function countDistinctActiveUsers(): int
    {
        return (int) UserSession::where('is_active', 1)->distinct('user_id')->count('user_id');
    }

    public function find($id): ?UserSession
    {
        return UserSession::find($id);
    }

    public function revoke($id, $revokedBy): bool
    {
        // يلغي الجلسة + الـ refresh token بتاعها، فالمستخدم يتطرد فعلًا
        // (الـ middleware بيرفض الـ access token لما الجلسة تبقى is_active=0).
        return app(\App\Services\UserSessionService::class)->end((int) $id, 'revoked_by_security', $revokedBy ? (int) $revokedBy : null);
    }
}
