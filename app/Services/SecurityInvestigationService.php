<?php

namespace App\Services;

use App\Repositories\BlockedIpRepository;
use App\Repositories\UserRepository;
use App\Services\Identity\TokenRevocationService;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مركز التحقيق في حساب مستخدم (Security Portal — بند 2).
 *
 * بيجمع في مكان واحد اللي كان مفرّق بين صفحات الجلسات/السجلات/التنبيهات/الحوادث: الجلسات، الأجهزة، الـ IPs، فشل الدخول،
 * حالة الـ MFA، تنبيهات/حوادث/تفعيلات قواعد الكشف الخاصة بالحساب + مستوى خطورة محسوب بعوامل مفسّرة (مش رقم أسود).
 * والإجراءات السريعة (إنهاء الجلسات / قفل / فتح / إعادة ضبط MFA / حظر IP) كلها بتعدّي من هنا عشان قواعد الحماية واحدة
 * والتدقيق (audit + security log) بيتسجّل في مكان واحد.
 *
 * مفيش بيانات حسّاسة بتطلع: مفيش session_token ولا device_hash كامل ولا two_factor_secret ولا recovery codes (العدد بس).
 */
class SecurityInvestigationService
{
    private const PRIVILEGED = ['admin', 'security_admin', 'security_officer'];
    /** الأدوار اللي مينفعش غير admin يقفلها/يصفّر MFA بتاعها. */
    private const PROTECTED = ['admin', 'security_admin'];

    public function __construct(
        private UserRepository $users,
        private TokenRevocationService $revocation,
        private AccountLockoutService $lockout,
        private BlockedIpRepository $blockedIps,
        private AuditLogService $audit
    ) {
    }

    // ------------------------------------------------------------------ search

    /** @return array<int,array<string,mixed>> */
    public function search(string $q, int $limit = 15): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $isIp = filter_var($q, FILTER_VALIDATE_IP) !== false;

        $rows = DB::table('users as u')
            ->where(function ($w) use ($like, $q, $isIp) {
                $w->where('u.full_name', 'like', $like)->orWhere('u.email', 'like', $like);
                // بحث بعنوان IP: الحسابات اللي دخلت منه فعلًا (آخر IP دخول / جلسة / دخول ناجح) — من شاشة Live Threat Monitor.
                if ($isIp) {
                    $w->orWhere('u.last_login_ip', $q)
                        ->orWhereIn('u.id', DB::table('user_sessions')->select('user_id')->where('ip_address', $q));
                    if (Schema::hasTable('login_events')) {
                        $w->orWhereIn('u.id', DB::table('login_events')->select('user_id')->where('ip_address', $q)->where('success', 1)->whereNotNull('user_id'));
                    }
                }
                if (ctype_digit($q)) {
                    $w->orWhere('u.id', (int) $q);
                }
                if (preg_match('/^[0-9a-f\-]{8,36}$/i', $q)) {
                    $w->orWhere('u.uuid', $q);
                }
            })
            ->selectRaw('u.id, u.full_name, u.email, u.status, u.deleted_at, u.lock_permanent, u.locked_until,
                (SELECT r.slug FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = u.id ORDER BY ur.assigned_at ASC LIMIT 1) AS role_slug')
            ->orderByRaw('CASE WHEN u.deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('u.full_name')
            ->limit(max(1, min(30, $limit)))
            ->get();

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'full_name' => $r->full_name, 'email' => $r->email, 'status' => $r->status,
            'role_slug' => $r->role_slug, 'deleted' => $r->deleted_at !== null,
            'locked' => $this->isLocked($r),
        ])->all();
    }

    // ------------------------------------------------------------------ profile

    /** @return array<string,mixed>|null */
    public function profile(int $userId): ?array
    {
        $u = DB::table('users')->where('id', $userId)->first();
        if (!$u) {
            return null;
        }

        $roles = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)->orderBy('ur.assigned_at')
            ->get(['r.slug', 'r.name_en', 'r.name_ar'])->map(fn ($r) => (array) $r)->all();
        $slugs = array_column($roles, 'slug');

        $hasEvents = Schema::hasTable('login_events');
        $now = now();

        // --- sessions
        $sessionCols = ['id', 'ip_address', 'user_agent', 'device_label', 'location_label', 'is_active', 'last_activity_at', 'created_at', 'expires_at', 'revoked_at', 'revoke_reason', 'revoked_by'];
        $active = DB::table('user_sessions')->where('user_id', $userId)->where('is_active', 1)
            ->orderByDesc('last_activity_at')->limit(50)->get($sessionCols)->map(fn ($r) => (array) $r)->all();
        $ended = DB::table('user_sessions')->where('user_id', $userId)->where('is_active', 0)
            ->orderByDesc('id')->limit(15)->get($sessionCols)->map(fn ($r) => (array) $r)->all();

        // --- sign-ins / devices / IPs
        $events = [];
        $stats = ['failed_24h' => 0, 'failed_7d' => 0, 'success_7d' => 0, 'last_failed_at' => null, 'last_success_at' => null, 'new_device_7d' => 0];
        $devices = [];
        $ips = [];
        if ($hasEvents) {
            $base = fn () => DB::table('login_events')->where(function ($w) use ($userId, $u) {
                $w->where('user_id', $userId)->orWhere('identifier', $u->email);
            });

            $events = $base()->orderByDesc('id')->limit(60)
                ->selectRaw('id, success, method, failure_reason, ip_address, device_label, location_label, is_new_device, created_at, SUBSTR(device_hash, 1, 8) as device_short')
                ->get()->map(fn ($r) => (array) $r)->all();

            $stats['failed_24h'] = (int) $base()->where('success', 0)->where('created_at', '>=', $now->copy()->subDay())->count();
            $stats['failed_7d'] = (int) $base()->where('success', 0)->where('created_at', '>=', $now->copy()->subDays(7))->count();
            $stats['success_7d'] = (int) $base()->where('success', 1)->where('created_at', '>=', $now->copy()->subDays(7))->count();
            $stats['new_device_7d'] = (int) $base()->where('success', 1)->where('is_new_device', 1)->where('created_at', '>=', $now->copy()->subDays(7))->count();
            $stats['last_failed_at'] = $base()->where('success', 0)->max('created_at');
            $stats['last_success_at'] = $base()->where('success', 1)->max('created_at');

            $since = $now->copy()->subDays(90);
            $devices = $base()->whereNotNull('device_hash')->where('created_at', '>=', $since)
                ->selectRaw("SUBSTR(device_hash, 1, 8) as device_short, MAX(device_label) as device_label, COUNT(*) as events,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as ok, SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed,
                    MIN(created_at) as first_seen, MAX(created_at) as last_seen")
                ->groupBy(DB::raw('SUBSTR(device_hash, 1, 8)'))->orderByDesc('last_seen')->limit(20)->get()
                ->map(fn ($r) => (array) $r)->all();

            $ips = $base()->whereNotNull('ip_address')->where('created_at', '>=', $since)
                ->selectRaw("ip_address, MAX(location_label) as location_label, COUNT(*) as events,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as ok, SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed,
                    MIN(created_at) as first_seen, MAX(created_at) as last_seen")
                ->groupBy('ip_address')->orderByDesc('last_seen')->limit(25)->get()
                ->map(fn ($r) => (array) $r)->all();
        }

        // IPs: محظور؟ ومشترك مع حسابات تانية؟ (مؤشر NAT أو حساب مخترق/عصابة حسابات)
        $ipList = array_column($ips, 'ip_address');
        $blocked = $ipList ? $this->blockedIps->activeAmong($ipList) : [];
        $shared = [];
        if ($hasEvents && $ipList) {
            $shared = DB::table('login_events')->whereIn('ip_address', $ipList)->where('success', 1)
                ->where('user_id', '!=', $userId)->where('created_at', '>=', $now->copy()->subDays(30))
                ->selectRaw('ip_address, COUNT(DISTINCT user_id) as c')->groupBy('ip_address')->pluck('c', 'ip_address')->all();
        }
        foreach ($ips as &$ip) {
            $ip['blocked'] = in_array($ip['ip_address'], $blocked, true);
            $ip['other_accounts'] = (int) ($shared[$ip['ip_address']] ?? 0);
        }
        unset($ip);

        // --- MFA
        $passkeys = Schema::hasTable('passkeys') ? (int) DB::table('passkeys')->where('user_id', $userId)->whereNull('revoked_at')->count() : 0;
        $trusted = Schema::hasTable('trusted_devices')
            ? DB::table('trusted_devices')->where('user_id', $userId)->whereNull('revoked_at')->where('expires_at', '>', $now)
                ->orderByDesc('last_used_at')->limit(20)->get(['id', 'device_label', 'ip_address', 'created_at', 'last_used_at', 'expires_at'])->map(fn ($r) => (array) $r)->all()
            : [];
        $recovery = json_decode((string) $u->two_factor_recovery_codes, true);
        $enforced = false;
        try {
            $mfaPolicy = app(MfaPolicyService::class);
            foreach ($slugs as $s) {
                $enforced = $enforced || $mfaPolicy->appliesToRole($s);
            }
        } catch (\Throwable) {
        }
        $mfa = [
            'totp_enabled'    => (bool) $u->two_factor_enabled,
            'confirmed_at'    => $u->two_factor_confirmed_at,
            'recovery_left'   => is_array($recovery) ? count($recovery) : 0,
            'passkeys'        => $passkeys,
            'trusted_devices' => $trusted,
            'enforced'        => $enforced,
            'grace_started'   => $u->mfa_grace_started_at,
            'protected'       => (bool) $u->two_factor_enabled || $passkeys > 0,
        ];

        // --- alerts / incidents / detection hits / logs
        $alerts = DB::table('security_alerts')->where('user_id', $userId)->orderByDesc('id')->limit(15)
            ->get(['id', 'type', 'severity', 'title', 'status', 'source_ip', 'created_at'])->map(fn ($r) => (array) $r)->all();
        $openAlerts = DB::table('security_alerts')->where('user_id', $userId)->whereIn('status', ['open', 'escalated'])
            ->selectRaw('severity, COUNT(*) as c')->groupBy('severity')->pluck('c', 'severity')->all();
        $incidents = DB::table('security_incidents')->where('affected_user_id', $userId)->orderByDesc('id')->limit(10)
            ->get(['id', 'reference_code', 'title', 'severity', 'status', 'detected_at'])->map(fn ($r) => (array) $r)->all();
        $openIncidents = (int) DB::table('security_incidents')->where('affected_user_id', $userId)->whereIn('status', ['open', 'investigating', 'contained'])->count();

        $hits = [];
        $hits7 = 0;
        if (Schema::hasTable('security_detection_hits')) {
            $hits = DB::table('security_detection_hits as h')->leftJoin('security_detection_rules as r', 'r.id', '=', 'h.rule_id')
                ->where('h.user_id', $userId)->orderByDesc('h.id')->limit(10)
                ->get(['h.id', 'h.rule_key', 'h.severity', 'h.observed', 'h.threshold', 'h.created_at', 'r.name_en as rule_name', 'r.name_ar as rule_name_ar'])
                ->map(fn ($r) => (array) $r)->all();
            $hits7 = (int) DB::table('security_detection_hits')->where('user_id', $userId)->where('created_at', '>=', $now->copy()->subDays(7))->count();
        }

        $logs = DB::table('security_logs')->where('user_id', $userId)->orderByDesc('id')->limit(15)
            ->get(['id', 'event_type', 'ip_address', 'severity', 'created_at'])->map(fn ($r) => (array) $r)->all();

        $countries = Schema::hasTable('security_user_baselines')
            ? DB::table('security_user_baselines')->where('user_id', $userId)->where('kind', 'country')->orderByDesc('last_seen_at')->limit(10)->get(['value', 'seen_count', 'first_seen_at', 'last_seen_at'])->map(fn ($r) => (array) $r)->all()
            : [];

        $locked = $this->isLocked($u);
        $risk = $this->risk([
            'open_alerts' => $openAlerts, 'failed_24h' => $stats['failed_24h'], 'hits_7d' => $hits7, 'open_incidents' => $openIncidents,
            'privileged' => (bool) array_intersect($slugs, self::PRIVILEGED), 'mfa' => $mfa,
            'active_ips' => count(array_unique(array_filter(array_column($active, 'ip_address')))),
            'active_locations' => count(array_unique(array_filter(array_column($active, 'location_label')))),
            'new_device_7d' => $stats['new_device_7d'],
        ]);

        return [
            'user' => [
                'id' => (int) $u->id, 'uuid' => $u->uuid, 'full_name' => $u->full_name, 'email' => $u->email, 'phone' => $u->phone,
                'status' => $u->status, 'deleted' => $u->deleted_at !== null, 'roles' => $roles,
                'created_at' => $u->created_at, 'last_login_at' => $u->last_login_at, 'last_login_ip' => $u->last_login_ip,
                'password_changed_at' => $u->password_changed_at, 'must_change_password' => (bool) $u->must_change_password,
                'email_verified' => $u->email_verified_at !== null,
            ],
            'lock' => [
                'locked' => $locked, 'permanent' => (bool) $u->lock_permanent, 'until' => $u->locked_until, 'reason' => $u->lock_reason,
                'locked_at' => $u->locked_at, 'failed_attempts' => (int) $u->failed_login_attempts,
            ],
            'risk'      => $risk,
            'mfa'       => $mfa,
            'stats'     => $stats + ['active_sessions' => count($active), 'devices_90d' => count($devices), 'ips_90d' => count($ips)],
            'sessions'  => ['active' => $active, 'ended' => $ended],
            'sign_ins'  => $events,
            'devices'   => $devices,
            'ips'       => $ips,
            'countries' => $countries,
            'alerts'    => $alerts,
            'incidents' => $incidents,
            'hits'      => $hits,
            'logs'      => $logs,
            'meta'      => ['login_events_available' => $hasEvents],
        ];
    }

    /**
     * مستوى الخطورة: مجموع نقاط عوامل مفسّرة (0..100). كل عامل: key ثابت (الفرونت بيترجمه) + points + n.
     *
     * @param array<string,mixed> $c
     * @return array{score:int,level:string,factors:array<int,array<string,mixed>>}
     */
    private function risk(array $c): array
    {
        $f = [];
        $add = function (string $key, int $points, $n = null) use (&$f) {
            if ($points > 0) {
                $f[] = ['key' => $key, 'points' => $points, 'n' => $n];
            }
        };

        $open = $c['open_alerts'];
        $alertPts = 25 * (int) ($open['critical'] ?? 0) + 15 * (int) ($open['high'] ?? 0) + 6 * (int) ($open['warning'] ?? 0) + 2 * (int) ($open['info'] ?? 0);
        $add('open_alerts', min(40, $alertPts), array_sum(array_map('intval', $open)));

        $fails = (int) $c['failed_24h'];
        $add('failed_logins_24h', $fails >= 20 ? 20 : ($fails >= 10 ? 14 : ($fails >= 5 ? 8 : ($fails >= 3 ? 4 : 0))), $fails);
        $add('detection_hits_7d', min(15, 5 * (int) $c['hits_7d']), (int) $c['hits_7d']);
        $add('open_incident', $c['open_incidents'] > 0 ? 15 : 0, (int) $c['open_incidents']);

        $mfa = $c['mfa'];
        if (!$mfa['protected']) {
            if ($c['privileged']) {
                $add('privileged_no_mfa', 15);
            } elseif ($mfa['enforced']) {
                $add('mfa_required_missing', 10);
            }
        }
        $add('many_active_ips', (int) $c['active_ips'] >= 3 ? 8 : 0, (int) $c['active_ips']);
        $add('many_active_locations', (int) $c['active_locations'] >= 2 ? 6 : 0, (int) $c['active_locations']);
        $add('new_device_recent', (int) $c['new_device_7d'] > 0 ? 4 : 0, (int) $c['new_device_7d']);

        $score = min(100, array_sum(array_column($f, 'points')));
        $level = $score >= 70 ? 'critical' : ($score >= 45 ? 'high' : ($score >= 20 ? 'medium' : 'low'));
        usort($f, fn ($a, $b) => $b['points'] <=> $a['points']);

        return ['score' => $score, 'level' => $level, 'factors' => $f];
    }

    // ------------------------------------------------------------------ actions

    /** @return string|null سبب المنع (null = مسموح). */
    public function guard(int $targetId, int $actorId, string $actorRole, bool $sensitive): ?string
    {
        if (!DB::table('users')->where('id', $targetId)->exists()) {
            return 'User not found.';
        }
        if ($sensitive) {
            if ($targetId === $actorId) {
                return 'You cannot do this to your own account.';
            }
            $slugs = $this->roleSlugs($targetId);
            if (array_intersect($slugs, self::PROTECTED) && $actorRole !== 'admin') {
                return 'Only a platform administrator can do this to an administrator account.';
            }
        }
        return null;
    }

    /** @return array{sessions:int,refresh_tokens:int} */
    public function revokeSessions(int $userId, int $actorId, ?string $ip, ?string $note): array
    {
        $res = $this->revocation->revokeAll($userId, 'security_investigation', $actorId);
        $this->done($actorId, 'security.investigation.revoke_sessions', $userId, $ip, ['sessions' => $res['sessions'], 'note' => $note]);
        return $res;
    }

    public function lock(int $userId, int $actorId, int $minutes, string $reason, bool $endSessions, ?string $ip): array
    {
        DB::table('users')->where('id', $userId)->update([
            'locked_until'   => $minutes > 0 ? now()->addMinutes($minutes) : null,
            'lock_permanent' => $minutes > 0 ? 0 : 1,
            'lock_reason'    => mb_substr($reason, 0, 250),
            'locked_at'      => now(),
        ]);
        Cache::forget('acct:status:' . \App\Services\Tenancy\TenantContext::ns() . $userId);
        $ended = $endSessions ? $this->revocation->revokeAll($userId, 'account_locked', $actorId)['sessions'] : 0;
        $this->done($actorId, 'security.investigation.lock', $userId, $ip, ['minutes' => $minutes, 'reason' => $reason, 'sessions_ended' => $ended]);
        return ['sessions_ended' => $ended];
    }

    public function unlock(int $userId, int $actorId, ?string $ip): void
    {
        $this->lockout->manualUnlock($userId, $actorId, $ip);
        Cache::forget('acct:status:' . \App\Services\Tenancy\TenantContext::ns() . $userId);
        $this->done($actorId, 'security.investigation.unlock', $userId, $ip, []);
    }

    /** يقفل TOTP + recovery codes + passkeys + الأجهزة الموثوقة. بعدها المستخدم يسجّل MFA من جديد (حسب السياسة). */
    public function resetMfa(int $userId, int $actorId, bool $endSessions, ?string $ip): array
    {
        $this->users->disableTwoFactor($userId);
        $passkeys = 0;
        if (Schema::hasTable('passkeys')) {
            $passkeys = (int) DB::table('passkeys')->where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
        try {
            app(TrustedDeviceService::class)->revokeAllForUser($userId);
        } catch (\Throwable) {
        }
        $this->users->setMfaGraceStart($userId, null);
        $ended = $endSessions ? $this->revocation->revokeAll($userId, 'mfa_reset', $actorId)['sessions'] : 0;
        $this->done($actorId, 'security.investigation.reset_mfa', $userId, $ip, ['passkeys_revoked' => $passkeys, 'sessions_ended' => $ended]);
        return ['passkeys_revoked' => $passkeys, 'sessions_ended' => $ended];
    }

    public function requirePasswordChange(int $userId, int $actorId, bool $endSessions, ?string $ip): array
    {
        DB::table('users')->where('id', $userId)->update(['must_change_password' => 1]);
        $ended = $endSessions ? $this->revocation->revokeAll($userId, 'password_change_required', $actorId)['sessions'] : 0;
        $this->done($actorId, 'security.investigation.require_password_change', $userId, $ip, ['sessions_ended' => $ended]);
        return ['sessions_ended' => $ended];
    }

    /** @return string|null خطأ، أو null لو اتحظر. بنحظر IPs ظهرت في تاريخ الحساب ده بس (مش أي IP عشوائي). */
    public function blockIp(int $userId, int $actorId, string $ip, int $minutes, ?string $actorIp): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return 'Invalid IP address.';
        }
        if ($ip === $actorIp) {
            return 'You cannot block your own IP address.';
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'Private/local addresses cannot be blocked from here.';
        }
        $seen = DB::table('user_sessions')->where('user_id', $userId)->where('ip_address', $ip)->exists()
            || (Schema::hasTable('login_events') && DB::table('login_events')->where('user_id', $userId)->where('ip_address', $ip)->exists());
        if (!$seen) {
            return 'That IP address is not in this account\'s history.';
        }
        $ok = $this->blockedIps->blockTimed($ip, 'Blocked from account investigation (user #' . $userId . ')', $actorId, $minutes > 0 ? now()->addMinutes($minutes) : null);
        if (!$ok) {
            return 'That IP address is already blocked.';
        }
        $this->done($actorId, 'security.investigation.block_ip', $userId, $actorIp, ['ip' => $ip, 'minutes' => $minutes]);
        return null;
    }

    // ------------------------------------------------------------------ helpers

    /** @return string[] */
    public function roleSlugs(int $userId): array
    {
        return DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $userId)->pluck('r.slug')->all();
    }

    private function isLocked(object $u): bool
    {
        return (bool) $u->lock_permanent || ($u->locked_until && strtotime((string) $u->locked_until) > time());
    }

    private function done(int $actorId, string $action, int $userId, ?string $ip, array $detail): void
    {
        $this->audit->record($actorId, $action, 'User', $userId, null, $detail ?: null, $ip);
        SecurityLog::write('Account investigation action: ' . $action, ['user_id' => $userId, 'actor_id' => $actorId] + $detail);
    }
}
