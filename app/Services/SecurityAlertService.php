<?php

namespace App\Services;

use App\Repositories\SecurityAlertRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * المصدر الوحيد لتوليد التنبيهات الأمنية الحقيقية (security_alerts).
 *
 * كان الجدول بيتقرا بس ومفيش كود بيكتب فيه، فصفحة Alerts كانت فاضية دايمًا.
 * دلوقتي بتتولّد تنبيهات من أحداث فعلية: محاولات دخول فاشلة، brute force
 * من IP واحد، قفل حساب، جلسات متعددة من عناوين مختلفة، دخول من دولة
 * جديدة، ومنح صلاحيات عالية.
 *
 * التكرار بيتجمّع: لو فيه تنبيه open بنفس النوع لنفس المستخدم/الـ IP خلال
 * فترة قصيرة بنزوّد عدّاد occurrences بدل ما نغرق القائمة بصف لكل حدث.
 * أي فشل هنا بيتسجّل بس ومبيكسرش الطلب الأصلي (login مثلًا).
 */
class SecurityAlertService
{
    public const BRUTE_FORCE_THRESHOLD = 10;   // محاولات فاشلة من نفس الـ IP
    public const BRUTE_FORCE_WINDOW_MIN = 10;
    public const MULTI_SESSION_THRESHOLD = 3;  // جلسات نشطة لنفس المستخدم

    public function __construct(private SecurityAlertRepository $alerts)
    {
    }

    /**
     * @param array<string,mixed> $meta
     * @return int|null id التنبيه (الجديد أو اللي اتزوّد)
     */
    public function raise(
        string $type,
        string $severity,
        string $title,
        ?string $message = null,
        ?string $ip = null,
        ?int $userId = null,
        array $meta = [],
        int $dedupeMinutes = 30
    ): ?int {
        try {
            $existing = DB::table('security_alerts')
                ->where('type', $type)
                ->where('status', 'open')
                ->where('created_at', '>=', now()->subMinutes($dedupeMinutes))
                ->when($userId !== null, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->whereNull('user_id'))
                ->when($userId === null && $ip !== null, fn ($q) => $q->where('source_ip', $ip))
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                $old = json_decode((string) $existing->meta, true) ?: [];
                $meta = array_merge($old, $meta, [
                    'occurrences' => ((int) ($old['occurrences'] ?? 1)) + 1,
                    'last_seen_at' => now()->toDateTimeString(),
                ]);
                $this->alerts->bumpOccurrence((int) $existing->id, $meta);
                return (int) $existing->id;
            }

            $alert = $this->alerts->create([
                'type'      => $type,
                'severity'  => $severity,
                'title'     => mb_substr($title, 0, 200),
                'message'   => $message,
                'source_ip' => $ip,
                'user_id'   => $userId,
                'status'    => 'open',
                'meta'      => array_merge(['occurrences' => 1], $meta),
            ]);

            return (int) $alert->id;
        } catch (\Throwable $e) {
            Log::warning('Security alert could not be recorded: ' . $e->getMessage(), ['type' => $type]);
            return null;
        }
    }

    /** محاولة دخول فاشلة (حساب موجود أو لا). بتولّد failed_login، ولو الـ IP عدّى الحد بتولّد brute_force. */
    public function failedLogin(string $email, ?string $ip, ?int $userId): void
    {
        $this->raise(
            'failed_login',
            'info',
            'Failed login attempts',
            $userId ? "Repeated failed sign-in attempts for {$email}." : "Failed sign-in attempts for unknown account {$email}.",
            $ip,
            $userId,
            ['email' => $email]
        );

        if (!$ip) {
            return;
        }
        $key = 'sec:fail-ip:' . sha1($ip);
        Cache::add($key, 0, now()->addMinutes(self::BRUTE_FORCE_WINDOW_MIN));
        $count = Cache::increment($key);
        if ($count >= self::BRUTE_FORCE_THRESHOLD) {
            $this->raise(
                'brute_force',
                'high',
                'Possible brute-force attack',
                "{$count} failed sign-in attempts from {$ip} in the last " . self::BRUTE_FORCE_WINDOW_MIN . ' minutes.',
                $ip,
                null,
                ['attempts' => $count, 'window_minutes' => self::BRUTE_FORCE_WINDOW_MIN],
                self::BRUTE_FORCE_WINDOW_MIN
            );
        }
    }

    public function accountLocked(int $userId, string $email, ?string $ip, bool $permanent, ?string $until): void
    {
        $this->raise(
            'account_lockout',
            $permanent ? 'high' : 'warning',
            $permanent ? 'Account permanently locked' : 'Account temporarily locked',
            "Account {$email} was locked after repeated failed sign-in attempts" . ($until ? " (until {$until})." : '.'),
            $ip,
            $userId,
            ['permanent' => $permanent, 'locked_until' => $until]
        );
    }

    /** بعد فتح جلسة جديدة: جلسات كتير أو دخول من دولة جديدة. */
    public function newSession(int $userId, string $email, ?string $ip, ?string $location, ?int $sessionId): void
    {
        $active = DB::table('user_sessions')->where('user_id', $userId)->where('is_active', 1);
        $count = (clone $active)->count();
        $ips = (clone $active)->whereNotNull('ip_address')->distinct()->pluck('ip_address')->all();

        if ($count >= self::MULTI_SESSION_THRESHOLD && count($ips) >= 2) {
            $this->raise(
                'multiple_session_detection',
                'warning',
                'Multiple active sessions',
                "{$email} has {$count} active sessions from " . count($ips) . ' different IP addresses.',
                $ip,
                $userId,
                ['active_sessions' => $count, 'ips' => array_slice($ips, 0, 10)],
                120
            );
        }

        // Advanced > monitoring.alert_on_new_ip: الأدمن يقدر يطفّي تنبيه "دخول من مكان جديد".
        $alertOnNew = true;
        try {
            $row = app(\App\Repositories\SecurityPolicyRepository::class)->findByKey('monitoring.alert_on_new_ip');
            if ($row) {
                $alertOnNew = in_array(strtolower(trim((string) $row->value)), ['1', 'true', 'on', 'yes'], true);
            }
        } catch (\Throwable $e) {
            // نفضل على الافتراضي (شغال)
        }

        if ($location && $alertOnNew) {
            $seen = DB::table('user_sessions')
                ->where('user_id', $userId)
                ->when($sessionId, fn ($q) => $q->where('id', '!=', $sessionId))
                ->whereNotNull('location_label')
                ->distinct()
                ->pluck('location_label')
                ->all();
            if ($seen && !in_array($location, $seen, true)) {
                $this->raise(
                    'suspicious_login_location',
                    'warning',
                    'Sign-in from a new location',
                    "{$email} signed in from {$location}; previous sign-ins were from " . implode(', ', array_slice($seen, 0, 3)) . '.',
                    $ip,
                    $userId,
                    ['location' => $location, 'previous_locations' => array_slice($seen, 0, 5)],
                    240
                );
            }
        }
    }

    /** منح صلاحيات إدارية/أمنية لمستخدم موجود. */
    public function privilegeGranted(int $userId, string $roleSlug, ?int $actorId = null): void
    {
        $elevated = ['admin', 'security_admin', 'security_officer'];
        if (!in_array($roleSlug, $elevated, true)) {
            return;
        }
        $email = (string) DB::table('users')->where('id', $userId)->value('email');
        $this->raise(
            'privilege_escalation',
            $roleSlug === 'admin' || $roleSlug === 'security_admin' ? 'critical' : 'high',
            "Elevated role granted: {$roleSlug}",
            "{$email} was granted the {$roleSlug} role" . ($actorId ? " by user #{$actorId}." : '.'),
            request()?->ip(),
            $userId,
            ['role' => $roleSlug, 'granted_by' => $actorId],
            5
        );
    }
}
