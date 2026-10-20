<?php

namespace App\Services;

use App\Models\RefreshToken;
use App\Support\AuthCookies;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * تسجيل الجلسات الحقيقية في `user_sessions` — كانت مش بتتكتب خالص، فصفحة
 * Sessions في بورتال الأمان كانت فاضية.
 *
 * الجلسة = سلسلة refresh tokens لجهاز واحد. session_token = sha256 لـ refresh
 * token الحالي (بيتبدّل مع كل rotation)، والـ access token بيشيل claim
 * `sid` = id الصف، فالـ middleware يقدر يتأكد إن الجلسة لسه شغالة وبالتالي
 * الـ Revoke بيطرد المستخدم فعلًا (مش بس يغيّر علامة في الجدول).
 */
class UserSessionService
{
    private const TOUCH_EVERY_SECONDS = 60;

    public function __construct(
        private GeoIpService $geo,
        private SecurityAlertService $alerts
    ) {
    }

    /** يفتح جلسة جديدة بعد تسجيل دخول ناجح. @return int session id */
    public function start(int $userId, string $rawRefresh, int $refreshTtlSeconds, ?string $role = null): int
    {
        $request = request();
        $ip = $request?->ip();
        $ua = (string) ($request?->userAgent() ?? '');
        $location = $this->locationLabel($ip);

        // Concurrent session limit (Security > Policies > Sessions): لو مفعّل
        // بنفضّي مكان بإلغاء أقدم الجلسات قبل فتح الجديدة. أي فشل هنا ميمنعش
        // الـ login.
        try {
            app(SessionPolicyService::class)->enforceConcurrentLimit($userId);
        } catch (\Throwable $e) {
            Log::warning('Concurrent session limit check failed: ' . $e->getMessage());
        }

        // حد الأجهزة (Admin > Platform Policy > Devices per account): نفس المتصفح بيستبدل جلسته القديمة،
        // ولو وصلنا للحد أقدم جهاز بيتسجّل منه خروج عشان الجديد ياخد مكانه. أي فشل هنا ميمنعش الـ login.
        try {
            $this->enforceDeviceLimit($userId, $role);
        } catch (\Throwable $e) {
            Log::warning('Device limit enforcement failed: ' . $e->getMessage());
        }

        $row = [
            'user_id'          => $userId,
            'session_token'    => hash('sha256', $rawRefresh),
            'ip_address'       => $ip,
            'user_agent'       => $ua !== '' ? mb_substr($ua, 0, 255) : null,
            'device_label'     => $this->deviceLabel($ua),
            'location_label'   => $location,
            'is_active'        => 1,
            'last_activity_at' => now(),
            'created_at'       => now(),
            'expires_at'       => now()->addSeconds($refreshTtlSeconds),
        ];
        // بصمة المتصفح (لو الـ migration اتنفّذت): بتتحط في الرد كوكي uip_did عن طريق AuthCookies::attach.
        if ($request && $this->hasDeviceColumn()) {
            $row['device_hash'] = hash('sha256', AuthCookies::deviceId($request, true));
        }
        $id = (int) DB::table('user_sessions')->insertGetId($row);

        try {
            $email = (string) DB::table('users')->where('id', $userId)->value('email');
            $this->alerts->newSession($userId, $email, $ip, $location, $id);
        } catch (\Throwable $e) {
            Log::warning('Session alert check failed: ' . $e->getMessage());
        }

        return $id;
    }

    /** بصمة جهاز الطلب الحالي (من غير ما نطلّع واحدة جديدة)، أو null. */
    private function currentDeviceHash(): ?string
    {
        $request = request();
        if (!$request || !$this->hasDeviceColumn()) {
            return null;
        }
        $id = AuthCookies::deviceId($request, false);

        return $id !== '' ? hash('sha256', $id) : null;
    }

    /** الـ migration بتاعة device_hash ممكن تتأخر عن الكود: من غيرها الـ login لازم يفضل شغال. */
    private function hasDeviceColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Schema::hasColumn('user_sessions', 'device_hash');
            } catch (\Throwable) {
                $has = false;
            }
        }
        return $has;
    }

    /** المدة (ثواني) اللي الجلسة بتفضل "حية" بعد آخر نبضة (للعرض بس — مبقتش بتمنع دخول). */
    public function liveSeconds(): int
    {
        return max(30, (int) \App\Support\PlatformPolicy::value('session_live_seconds'));
    }

    /**
     * الأجهزة اللي الحساب مسجّل دخول عليها دلوقتي (جلسة نشطة غير منتهية)، الأحدث نشاطًا الأول.
     * الجهاز = بصمة المتصفح؛ جلسة من غير بصمة (قديمة) بتتحسب جهاز لوحدها.
     * كل عنصر: ['key', 'session_ids' => [...], 'device_hash', 'device_label', 'location_label', 'last_activity_at'].
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeDevices(int $userId): array
    {
        $cols = ['id', 'device_label', 'location_label', 'last_activity_at'];
        if ($this->hasDeviceColumn()) {
            $cols[] = 'device_hash';
        }
        $rows = DB::table('user_sessions')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get($cols);

        $devices = [];
        foreach ($rows as $r) {
            $hash = $r->device_hash ?? null;
            $key = $hash ? 'd:' . $hash : 's:' . $r->id;
            if (!isset($devices[$key])) {
                $devices[$key] = [
                    'key'              => $key,
                    'session_ids'      => [],
                    'device_hash'      => $hash ?: null,
                    'device_label'     => $r->device_label,
                    'location_label'   => $r->location_label,
                    'last_activity_at' => $r->last_activity_at,
                ];
            }
            $devices[$key]['session_ids'][] = (int) $r->id;
        }

        return array_values($devices);
    }

    /**
     * يطبّق حد الأجهزة قبل فتح جلسة جديدة:
     *  1) أي جلسة قديمة لنفس المتصفح بتتقفل (نفس الجهاز ميتحسبش مرتين).
     *  2) لو الحد (حسب دور الحساب) > 0 والأجهزة التانية وصلت له، أقدم الأجهزة نشاطًا بتتسجّل خروج
     *     عشان يفضل مكان للجهاز الجديد. الحد 0 = مفيش حد.
     *
     * @return int عدد الجلسات اللي اتقفلت
     */
    public function enforceDeviceLimit(int $userId, ?string $role = null): int
    {
        $ended = 0;
        $mine = $this->currentDeviceHash();
        $others = [];
        foreach ($this->activeDevices($userId) as $d) {
            if ($mine !== null && $d['device_hash'] === $mine) {
                foreach ($d['session_ids'] as $sid) {
                    $ended += $this->end($sid, 'same_device_relogin') ? 1 : 0;
                }
                continue;
            }
            $others[] = $d;
        }

        $limit = \App\Support\PlatformPolicy::deviceLimitFor($role ?? app(RoleService::class)->primaryRoleFor($userId));
        if ($limit < 1) {
            return $ended;
        }
        $excess = count($others) - ($limit - 1); // نسيب مكان للجهاز الجديد
        if ($excess > 0) {
            // $others مترتبة من الأحدث للأقدم: بنشيل من الآخر.
            foreach (array_slice($others, -$excess) as $d) {
                foreach ($d['session_ids'] as $sid) {
                    $ended += $this->end($sid, 'device_limit') ? 1 : 0;
                }
            }
        }

        return $ended;
    }

    /**
     * لو الدخول الجديد لازم يتمنع (وضع "منع الدخول الجديد" + الحساب وصل لحد الأجهزة) يرجّع [message, data]،
     * وإلا null. بيتنادى بعد التأكد من كلمة السر عشان محدش يعرف حالة حساب مش بتاعه.
     */
    public function loginBlock(int $userId, string $locale, ?string $role = null): ?array
    {
        if (\App\Support\PlatformPolicy::deviceMode() !== 'block') {
            return null;
        }
        $limit = \App\Support\PlatformPolicy::deviceLimitFor($role ?? app(RoleService::class)->primaryRoleFor($userId));
        if ($limit < 1) {
            return null;
        }
        $mine = $this->currentDeviceHash();
        // نفس المتصفح اللي عنده جلسة (رجع بعد مسح الدخول محليًا مثلًا) مش "جهاز جديد": start() بيستبدل جلسته.
        $others = array_values(array_filter($this->activeDevices($userId), fn ($d) => $mine === null || $d['device_hash'] !== $mine));
        if (count($others) < $limit) {
            return null;
        }

        $message = $locale === 'ar'
            ? "الحساب ده وصل للحد الأقصى من الأجهزة ({$limit}). سجّل خروج من أحد الأجهزة المفتوحة الأول وبعدين جرّب تاني."
            : "This account has reached its device limit ({$limit}). Sign out of one of the open devices first, then try again.";

        // takeover_token: يثبت إن كلمة السر اتكتبت صح، وبيفتح مسار "مش أنا" (OTP) ومسار "سجّل خروج من جهاز".
        $takeover = \App\Services\UipJwtService::encode(['typ' => 'session_takeover', 'sub' => $userId], 900);

        return [$message, [
            'code'           => 'account_in_use',
            'reason'         => 'device_limit',
            'device_limit'   => $limit,
            'devices'        => array_map(fn ($d) => [
                'id'               => $d['session_ids'][0],
                'device_label'     => $d['device_label'],
                'location_label'   => $d['location_label'],
                'last_activity_at' => $d['last_activity_at'],
            ], $others),
            'takeover_token' => $takeover,
        ]];
    }

    /** نبضة: الجلسة لسه مفتوحة (بتحدّث "آخر نشاط" اللي بيظهر في قائمة الأجهزة). */
    public function heartbeat(int $sessionId): void
    {
        DB::table('user_sessions')->where('id', $sessionId)->where('is_active', 1)->update(['last_activity_at' => now()]);
    }

    /**
     * كان بيقصّر عمر الجلسة لما المتصفح يتقفل. دلوقتي الدخول دايم (زي فيسبوك): قفل المتصفح أو التبويب
     * مبيسجّلش خروج ومبيحرّرش الحساب — الطريق الوحيد للخروج هو تسجيل خروج صريح أو إلغاء الجهاز أو حد الأجهزة.
     * فضلت الدالة (من غير أثر) عشان نسخ الفرونت القديمة اللي لسه بتناديها متفضلش تعمل 404.
     */
    public function release(int $sessionId): void
    {
    }

    /** يقفل كل جلسات المستخدم النشطة (غير الاستثناء) — بيتنادى لما جلسة جديدة بتتفتح. */
    public function endOthers(int $userId, string $reason, ?int $exceptId = null): void
    {
        $ids = DB::table('user_sessions')->where('user_id', $userId)->where('is_active', 1)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->pluck('id')->all();
        foreach ($ids as $id) {
            $this->end((int) $id, $reason);
        }
    }

    /** Refresh: نفس الجلسة، refresh token جديد. */
    public function rotate(int $sessionId, string $newRawRefresh, int $refreshTtlSeconds): void
    {
        $update = [
            'session_token'    => hash('sha256', $newRawRefresh),
            'last_activity_at' => now(),
            'expires_at'       => now()->addSeconds($refreshTtlSeconds),
        ];
        // التوكن السابق بيفضل مقبول لمدة ROTATION_GRACE_SECONDS (رد ضاع في الشبكة) — شوف findByPrevHash().
        if ($this->hasPrevTokenColumn()) {
            $update['prev_token_hash'] = DB::table('user_sessions')->where('id', $sessionId)->value('session_token');
            $update['rotated_at'] = now();
        }
        DB::table('user_sessions')->where('id', $sessionId)->update($update);
    }

    /** كام ثانية بعد التبديل التوكن القديم لسه ينفع (بيحمي من ضياع رد الـ refresh من غير ما نفتح باب سرقة). */
    public const ROTATION_GRACE_SECONDS = 60;

    /** الجلسة النشطة اللي التوكن ده كان توكنها وبُدّل من أقل من دقيقة، أو null. */
    public function findByPrevHash(string $hash): ?object
    {
        if (!$this->hasPrevTokenColumn()) {
            return null;
        }
        return DB::table('user_sessions')
            ->where('prev_token_hash', $hash)
            ->where('is_active', 1)
            ->where('rotated_at', '>=', now()->subSeconds(self::ROTATION_GRACE_SECONDS))
            ->first() ?: null;
    }

    private function hasPrevTokenColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Schema::hasColumn('user_sessions', 'prev_token_hash');
            } catch (\Throwable) {
                $has = false;
            }
        }
        return $has;
    }

    public function findByRefreshHash(string $hash): ?object
    {
        return DB::table('user_sessions')->where('session_token', $hash)->first() ?: null;
    }

    /** هل الجلسة فضلت من غير أي نشاط أكتر من المدة المسموحة في Security > Policies > Sessions؟ */
    public function idleExpired(?object $row): bool
    {
        if (!$row || empty($row->last_activity_at)) {
            return false;
        }
        $limit = app(SessionPolicyService::class)->idleTimeoutSeconds();
        if ($limit <= 0) {
            return false;
        }
        $last = strtotime((string) $row->last_activity_at);
        return $last !== false && (time() - $last) > $limit;
    }

    /** هل الجلسة لسه شغالة؟ التوكنات القديمة (من غير sid) بتعدّي. */
    public function isActive(?int $sessionId): bool
    {
        if (!$sessionId) {
            return true;
        }
        $row = DB::table('user_sessions')->where('id', $sessionId)->first(['is_active', 'expires_at', 'last_activity_at']);
        if (!$row) {
            return false;
        }
        if (!(bool) $row->is_active || ($row->expires_at && strtotime((string) $row->expires_at) <= time())) {
            return false;
        }
        // خمول: الجلسة بتتقفل فعليًا (is_active=0 + revoke للـ refresh token) مش بس بترفض الطلب ده.
        if ($this->idleExpired($row)) {
            $this->end($sessionId, 'idle_timeout');
            return false;
        }
        return true;
    }

    /** يحدّث آخر نشاط بحد أقصى مرة كل دقيقة لكل جلسة. */
    public function touch(int $sessionId): void
    {
        $key = 'sess:touch:' . $sessionId;
        if (Cache::add($key, 1, self::TOUCH_EVERY_SECONDS)) {
            DB::table('user_sessions')->where('id', $sessionId)->where('is_active', 1)->update(['last_activity_at' => now()]);
        }
    }

    /** إنهاء جلسة (logout / revoke) + إلغاء الـ refresh token بتاعها. */
    public function end(int $sessionId, string $reason, ?int $revokedBy = null): bool
    {
        $row = DB::table('user_sessions')->where('id', $sessionId)->first();
        if (!$row) {
            return false;
        }
        DB::table('user_sessions')->where('id', $sessionId)->update([
            'is_active'     => 0,
            'revoked_by'    => $revokedBy,
            'revoked_at'    => now(),
            'revoke_reason' => $reason,
        ]);
        RefreshToken::where('token_hash', $row->session_token)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        return true;
    }

    /** كل جلسات مستخدم (refresh token reuse مثلًا). */
    public function endAllForUser(int $userId, string $reason): void
    {
        DB::table('user_sessions')->where('user_id', $userId)->where('is_active', 1)->update([
            'is_active' => 0, 'revoked_at' => now(), 'revoke_reason' => $reason,
        ]);
    }

    private function locationLabel(?string $ip): ?string
    {
        if (!$ip) {
            return null;
        }
        try {
            $r = $this->geo->resolve($ip);
        } catch (\Throwable) {
            return null;
        }
        if ($r['status'] === 'ok') {
            return $r['name'] ?: $r['code'];
        }
        return $r['status'] === 'local' ? 'Local network' : null;
    }

    private function deviceLabel(string $ua): ?string
    {
        if ($ua === '') {
            return null;
        }
        $browser = 'Browser';
        foreach ([
            'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox',
            'Chrome/' => 'Chrome', 'Safari/' => 'Safari', 'curl/' => 'curl',
        ] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $browser = $name;
                break;
            }
        }
        $os = 'Unknown OS';
        foreach ([
            'Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS',
            'Mac OS X' => 'macOS', 'Linux' => 'Linux',
        ] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $os = $name;
                break;
            }
        }
        return "{$browser} on {$os}";
    }
}
