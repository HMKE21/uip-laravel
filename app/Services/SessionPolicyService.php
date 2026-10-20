<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * getPolicy()/updatePolicy() + enforceConcurrentLimit() (بيتنادى من
 * UserSessionService::start وقت كل login ناجح). الـ concurrent limit
 * بقى بيتطبّق فعلًا: لو enforce_concurrent_limit شغّال والمستخدم وصل للحد،
 * أقدم جلساته بتتلغي (is_active=0 + revoke للـ refresh token) عشان الجلسة
 * الجديدة تاخد مكانها — فالجهاز القديم يتطرد بدل ما الدخول الجديد يتمنع.
 *
 * idle timeout: timeout_minutes بقى بيتطبّق فعلًا (idleTimeoutSeconds) — الجلسة اللي
 * مفيهاش أي نشاط المدة دي بتتقفل (UserSessionService::isActive + RefreshTokenController).
 */
class SessionPolicyService
{
    private const POLICY_KEY = 'session.policy';

    private const DEFAULTS = [
        'timeout_minutes'          => 60,
        'concurrent_limit'         => 0,
        'enforce_concurrent_limit' => false,
    ];

    public function __construct(
        private \App\Repositories\SecurityPolicyRepository $policies,
        private AuditLogService $auditLog
    ) {
    }

    private const IDLE_CACHE_KEY = 'session.policy.idle_seconds';

    /** مدة الخمول المسموحة بالثواني (0 = متعطّل). متكاشّة دقيقة عشان الـ middleware بيسألها كل request. */
    public function idleTimeoutSeconds(): int
    {
        try {
            // دخول دائم (Admin > Platform Policy): مفيش قفل بسبب الخمول — الجلسة بتفضل لحد تسجيل خروج صريح.
            if (\App\Support\PlatformPolicy::flag('persistent_login')) {
                return 0;
            }
            return (int) Cache::remember(self::IDLE_CACHE_KEY, 60, function () {
                $minutes = (int) ($this->getPolicy()['timeout_minutes'] ?? 0);
                return $minutes > 0 ? $minutes * 60 : 0;
            });
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** @return array{timeout_minutes:int,concurrent_limit:int,enforce_concurrent_limit:bool} */
    public function getPolicy(): array
    {
        $row = $this->policies->findByKey(self::POLICY_KEY);
        $stored = [];
        if ($row) {
            $decoded = json_decode((string) $row->value, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }
        return array_merge(self::DEFAULTS, $stored);
    }

    /** @throws \InvalidArgumentException on bad input */
    public function updatePolicy(array $input, $adminUserId, ?string $ip = null): array
    {
        $before = $this->getPolicy();

        $timeout = (int) ($input['timeout_minutes'] ?? 0);
        if ($timeout < 1 || $timeout > 43200) {
            throw new \InvalidArgumentException('Session timeout must be between 1 minute and 43200 minutes (30 days).');
        }

        $limit = (int) ($input['concurrent_limit'] ?? 0);
        if ($limit < 0 || $limit > 50) {
            throw new \InvalidArgumentException('Concurrent session limit must be between 0 (unlimited) and 50.');
        }

        $enforce = !empty($input['enforce_concurrent_limit']);
        if ($enforce && $limit < 1) {
            throw new \InvalidArgumentException('Set a concurrent session limit of at least 1 before enabling enforcement.');
        }

        $policy = [
            'timeout_minutes'          => $timeout,
            'concurrent_limit'         => $limit,
            'enforce_concurrent_limit' => $enforce,
        ];

        $saved = $this->policies->updateValue(self::POLICY_KEY, json_encode($policy, JSON_UNESCAPED_UNICODE), $adminUserId);
        if (!$saved) {
            throw new \RuntimeException('Could not save the session policy.');
        }

        Cache::forget(self::IDLE_CACHE_KEY);

        $this->auditLog->record($adminUserId, 'security_policy.session_updated', 'SecurityPolicy', null, $before, $policy, $ip);

        return $policy;
    }

    /**
     * يلغي أقدم الجلسات النشطة للمستخدم بحيث يفضل مكان لجلسة جديدة.
     * بيتنادى قبل إدخال الجلسة الجديدة. @return int عدد الجلسات اللي اتلغت
     */
    public function enforceConcurrentLimit(int $userId): int
    {
        $policy = $this->getPolicy();
        $limit = (int) $policy['concurrent_limit'];
        if (empty($policy['enforce_concurrent_limit']) || $limit < 1) {
            return 0;
        }

        $active = \Illuminate\Support\Facades\DB::table('user_sessions')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('last_activity_at')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $excess = count($active) - ($limit - 1); // نسيب مكان للجلسة الجديدة
        if ($excess <= 0) {
            return 0;
        }

        $sessions = app(UserSessionService::class);
        $ended = 0;
        foreach (array_slice($active, 0, $excess) as $sessionId) {
            if ($sessions->end((int) $sessionId, 'concurrent_limit')) {
                $ended++;
            }
        }
        return $ended;
    }
}
