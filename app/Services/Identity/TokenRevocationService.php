<?php

namespace App\Services\Identity;

use App\Models\RefreshToken;
use App\Services\AuditLogService;
use App\Services\TrustedDeviceService;
use App\Services\UserSessionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UIPE Mail — المرحلة 5: إلغاء التوكنز. بنبني على الموجود: كل access token ليه sid في user_sessions،
 * وإنهاء الجلسة (UserSessionService::end) بيبطّل الـ access والـ refresh فورًا.
 * الجديد: "الخروج من باقي الأجهزة" و"الخروج من كل الأجهزة" + users.tokens_valid_after (أي توكن صدر قبلها بيبطل حتى لو من غير sid)
 * + إلغاء الأجهزة الموثوقة. بيتنادى كمان من الأدمن/الجامعة ومن إيقاف العضوية.
 */
class TokenRevocationService
{
    public function __construct(private UserSessionService $sessions, private TrustedDeviceService $trusted, private AuditLogService $audit)
    {
    }

    /** يقفل كل جلسات المستخدم النشطة ما عدا $exceptSid. @return array{sessions:int,refresh_tokens:int} */
    public function revokeOthers(int $userId, ?int $exceptSid, string $reason = 'revoked_others', ?int $by = null): array
    {
        $ids = DB::table('user_sessions')->where('user_id', $userId)->where('is_active', 1)
            ->when($exceptSid, fn ($q) => $q->where('id', '!=', $exceptSid))->pluck('id')->all();
        $keepHash = $exceptSid ? DB::table('user_sessions')->where('id', $exceptSid)->value('session_token') : null;
        foreach ($ids as $id) {
            $this->sessions->end((int) $id, $reason, $by);
        }
        // أي refresh token لسه شغال مش بتاع الجلسة الحالية (لو فيه تتبّع مفقود).
        $refresh = RefreshToken::where('user_id', $userId)->whereNull('revoked_at')
            ->when($keepHash, fn ($q) => $q->where('token_hash', '!=', $keepHash))->update(['revoked_at' => now()]);
        return ['sessions' => count($ids), 'refresh_tokens' => (int) $refresh];
    }

    /** خروج كامل: كل الجلسات + كل الـ refresh tokens + الأجهزة الموثوقة + قطع أي access token قديم. */
    public function revokeAll(int $userId, string $reason = 'revoked_all', ?int $by = null): array
    {
        $res = $this->revokeOthers($userId, null, $reason, $by);
        try {
            $this->trusted->revokeAllForUser($userId);
        } catch (\Throwable) {
            // جدول الأجهزة الموثوقة مش لازم يوقف الإلغاء.
        }
        if (Schema::hasColumn('users', 'tokens_valid_after')) {
            DB::table('users')->where('id', $userId)->update(['tokens_valid_after' => now()]);
        }
        Cache::forget('acct:status:' . \App\Services\Tenancy\TenantContext::ns() . $userId);
        $this->audit->record($by ?? $userId, 'identity.revoke_all_tokens', 'User', $userId, null, ['reason' => $reason, 'sessions' => $res['sessions']]);
        return $res;
    }
}
