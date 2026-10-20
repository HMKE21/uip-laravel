<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DesktopSecurityService;
use App\Services\RoleService;
use App\Services\SessionPolicyService;
use App\Services\UserSessionService;
use App\Support\PlatformPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * /api/v1/desktop/security/* — "الإعدادات ▸ الأمان والأجهزة" في سطح المكتب. أي مستخدم مسجّل دخول، على حسابه هو بس.
 *
 *  GET  security                      الإعدادات + حدود المنصة + الأجهزة المفتوحة + آخر نشاط دخول/خروج
 *  PUT  security                      حفظ الإعدادات (maxDevices / autoLogoutMinutes بيتطبّقوا على السيرفر فعليًا)
 *  POST security/devices/{id}/logout  تسجيل خروج جهاز (كل جلسات نفس المتصفح)
 *  POST security/devices/{id}/lock    قفل شاشة جهاز تاني عن بُعد (بيتلقط في أول pulse)
 *  POST security/devices/logout-others  خروج من كل الأجهزة التانية
 *  POST security/devices/lock-others    قفل كل الأجهزة التانية
 *  GET  security/pulse                نبضة الجهاز (كل ~20 ث): أمر قفل عن بُعد + الإعدادات الحالية
 *
 * "الأشد يكسب": اختيار المستخدم بيشدّد سياسة المنصة بس، مبيفتحش أكتر منها.
 */
class DesktopSecurityApiController extends Controller
{
    public function __construct(private DesktopSecurityService $security, private UserSessionService $sessions)
    {
    }

    private function uid(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }

    private function sid(Request $r): int
    {
        return (int) $r->attributes->get('uip_session_id');
    }

    private function ar(Request $r): bool
    {
        return $r->header('X-Locale', 'ar') !== 'en';
    }

    private function hasLockCol(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Schema::hasColumn('user_sessions', 'remote_lock_at');
            } catch (\Throwable) {
                $has = false;
            }
        }

        return $has;
    }

    private function hasHashCol(): bool
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

    private static function kind(?string $ua): string
    {
        $ua = (string) $ua;
        if (preg_match('/iPad|Tablet/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/Mobi|Android|iPhone/i', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }

    /** الجلسات النشطة غير المنتهية للمستخدم. */
    private function activeRows(int $uid)
    {
        return DB::table('user_sessions')
            ->where('user_id', $uid)
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @return array<int,array<string,mixed>> الأجهزة (مجمّعة بصمة المتصفح)، الأحدث نشاطًا الأول. */
    private function devices(int $uid, int $currentSid): array
    {
        $live = $this->sessions->liveSeconds();
        $out = [];
        foreach ($this->activeRows($uid) as $r) {
            $hash = $this->hasHashCol() ? ($r->device_hash ?? null) : null;
            $key = $hash ? 'd:' . $hash : 's:' . $r->id;
            if (!isset($out[$key])) {
                $last = $r->last_activity_at ? strtotime((string) $r->last_activity_at) : false;
                $out[$key] = [
                    'id'               => (int) $r->id, // أي جلسة من الجهاز كافية كمرجع
                    'device_label'     => $r->device_label,
                    'kind'             => self::kind($r->user_agent),
                    'location_label'   => $r->location_label,
                    'ip_address'       => $r->ip_address,
                    'is_current'       => false,
                    'online'           => $last !== false && (time() - $last) <= max(120, $live),
                    'last_activity_at' => $r->last_activity_at,
                    'signed_in_at'     => $r->created_at,
                    'sessions'         => 0,
                ];
            }
            $out[$key]['sessions']++;
            if ($currentSid > 0 && (int) $r->id === $currentSid) {
                $out[$key]['is_current'] = true;
                $out[$key]['id'] = (int) $r->id;
            }
            if ($r->created_at && (!$out[$key]['signed_in_at'] || strtotime((string) $r->created_at) < strtotime((string) $out[$key]['signed_in_at']))) {
                $out[$key]['signed_in_at'] = $r->created_at;
            }
        }
        $list = array_values($out);
        usort($list, fn ($a, $b) => ($b['is_current'] <=> $a['is_current']) ?: strcmp((string) $b['last_activity_at'], (string) $a['last_activity_at']));

        return $list;
    }

    /** @return array<int,array<string,mixed>> آخر دخول/خروج (حتى الجلسات المنتهية). */
    private function history(int $uid, int $limit = 15): array
    {
        return DB::table('user_sessions')
            ->where('user_id', $uid)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'device_label', 'location_label', 'ip_address', 'is_active', 'created_at', 'revoked_at', 'revoke_reason', 'user_agent'])
            ->map(fn ($r) => [
                'id'             => (int) $r->id,
                'device_label'   => $r->device_label,
                'kind'           => self::kind($r->user_agent),
                'location_label' => $r->location_label,
                'ip_address'     => $r->ip_address,
                'active'         => (bool) $r->is_active,
                'signed_in_at'   => $r->created_at,
                'ended_at'       => $r->revoked_at,
                'end_reason'     => $r->revoke_reason,
            ])->all();
    }

    /** الحدود اللي المنصة فارضاها (عشان الفرونت يعرض "الحد الأقصى المسموح"). */
    private function limits(int $uid, ?string $role): array
    {
        $role = $role ?: app(RoleService::class)->primaryRoleFor($uid);
        $idle = app(SessionPolicyService::class)->idleTimeoutSeconds();

        return [
            'platformDeviceLimit' => PlatformPolicy::deviceLimitFor($role), // 0 = بلا حد
            'platformMode'        => PlatformPolicy::deviceMode(),          // replace_oldest | block
            'platformIdleMinutes' => $idle > 0 ? (int) ceil($idle / 60) : 0,
            'persistentLogin'     => PlatformPolicy::flag('persistent_login'),
            'maxChoice'           => DesktopSecurityService::MAX_DEVICES_CHOICE,
        ];
    }

    private function payload(Request $r): array
    {
        $uid = $this->uid($r);
        $sid = $this->sid($r);

        return [
            'settings'   => $this->security->get($uid),
            'limits'     => $this->limits($uid, (string) $r->attributes->get('uip_role')),
            'devices'    => $this->devices($uid, $sid),
            'history'    => $this->history($uid),
            'currentSid' => $sid ?: null,
        ];
    }

    // -- endpoints ---------------------------------------------------------------------------------------

    public function show(Request $request): JsonResponse
    {
        return $this->apiSuccess($this->payload($request), 'Security settings retrieved successfully.');
    }

    public function update(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $request->validate([
            'maxDevices'        => 'sometimes|integer|min:0|max:' . DesktopSecurityService::MAX_DEVICES_CHOICE,
            'deviceOverflow'    => 'sometimes|string|in:' . implode(',', DesktopSecurityService::OVERFLOW),
            'autoLockMinutes'   => 'sometimes|integer|in:' . implode(',', DesktopSecurityService::AUTO_LOCK),
            'sleepMinutes'      => 'sometimes|integer|in:' . implode(',', DesktopSecurityService::SLEEP),
            'lockOnHide'        => 'sometimes|integer|in:' . implode(',', DesktopSecurityService::LOCK_ON_HIDE),
            'autoLogoutMinutes' => 'sometimes|integer|in:' . implode(',', DesktopSecurityService::AUTO_LOGOUT),
            'lockOnStart'       => 'sometimes|boolean',
            'lockAfterSleep'    => 'sometimes|boolean',
            'alertNewDevice'    => 'sometimes|boolean',
            'hideLockDetails'   => 'sometimes|boolean',
            'sleepDimOnly'      => 'sometimes|boolean',
        ]);
        try {
            $saved = $this->security->save($uid, $request->only(array_keys(DesktopSecurityService::DEFAULTS)));
        } catch (\Throwable $e) {
            return $this->apiError($this->ar($request) ? 'مقدرناش نحفظ الإعدادات دلوقتي.' : 'Could not save the settings right now.', null, 503);
        }

        // حد الأجهزة الجديد بيتطبّق فورًا: لو الأجهزة المفتوحة أكتر منه، أقدم الأجهزة (غير الحالي) بتتسجّل خروج.
        $evicted = $this->trimDevices($uid, $this->sid($request), (string) $request->attributes->get('uip_role'));

        return $this->apiSuccess($this->payload($request) + ['evicted' => $evicted, 'saved' => $saved], 'Security settings saved successfully.');
    }

    /** يقلّل الأجهزة المفتوحة لحد الحد الفعلي، من غير ما يمس الجهاز الحالي. @return int عدد الأجهزة اللي اتسجّل خروجها */
    private function trimDevices(int $uid, int $currentSid, string $role): int
    {
        $limit = $this->sessions->effectiveDeviceLimit($uid, $role ?: null);
        if ($limit < 1) {
            return 0;
        }
        $devices = $this->devices($uid, $currentSid); // الحالي أول واحد
        $excess = count($devices) - $limit;
        if ($excess <= 0) {
            return 0;
        }
        $n = 0;
        foreach (array_slice(array_reverse($devices), 0, $excess) as $d) { // من الأقدم
            if ($d['is_current']) {
                continue;
            }
            $n += $this->endDevice($uid, (int) $d['id'], 'device_limit');
        }

        return $n;
    }

    /** يقفل كل جلسات نفس المتصفح اللي الجلسة $sessionId بتاعته. @return int عدد الجلسات */
    private function endDevice(int $uid, int $sessionId, string $reason): int
    {
        $ids = $this->deviceSessionIds($uid, $sessionId);
        $n = 0;
        foreach ($ids as $id) {
            $n += $this->sessions->end($id, $reason, $uid) ? 1 : 0;
        }

        return $n;
    }

    /** @return int[] */
    private function deviceSessionIds(int $uid, int $sessionId): array
    {
        $row = DB::table('user_sessions')->where('id', $sessionId)->where('user_id', $uid)->where('is_active', 1)->first();
        if (!$row) {
            return [];
        }
        $hash = $this->hasHashCol() ? ($row->device_hash ?? null) : null;
        if (!$hash) {
            return [(int) $row->id];
        }

        return DB::table('user_sessions')->where('user_id', $uid)->where('is_active', 1)->where('device_hash', $hash)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function logoutDevice(Request $request, int $id): JsonResponse
    {
        $uid = $this->uid($request);
        $ids = $this->deviceSessionIds($uid, $id);
        if (!$ids) {
            return $this->apiError($this->ar($request) ? 'الجهاز مش موجود أو خرج بالفعل.' : 'Device not found or already signed out.', null, 404);
        }
        $self = in_array($this->sid($request), $ids, true);
        foreach ($ids as $sid) {
            $this->sessions->end($sid, 'revoked_by_user', $uid);
        }

        return $this->apiSuccess(['ended' => count($ids), 'self' => $self], 'Device signed out.');
    }

    public function logoutOthers(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $cur = $this->sid($request);
        $ids = $this->activeRows($uid)->pluck('id')->map(fn ($v) => (int) $v)->all();
        if ($cur > 0) {
            // نفس متصفح الجلسة الحالية مبيتقفلش
            $mine = $this->deviceSessionIds($uid, $cur);
            $ids = array_values(array_diff($ids, $mine ?: [$cur]));
        }
        foreach ($ids as $sid) {
            $this->sessions->end($sid, 'revoked_by_user', $uid);
        }

        return $this->apiSuccess(['ended' => count($ids)], 'Other devices signed out.');
    }

    public function lockDevice(Request $request, int $id): JsonResponse
    {
        if (!$this->hasLockCol()) {
            return $this->apiError($this->ar($request) ? 'الميزة دي محتاجة تحديث قاعدة البيانات.' : 'This needs a database update.', null, 503);
        }
        $uid = $this->uid($request);
        $ids = $this->deviceSessionIds($uid, $id);
        if (!$ids) {
            return $this->apiError($this->ar($request) ? 'الجهاز مش موجود أو خرج بالفعل.' : 'Device not found or already signed out.', null, 404);
        }
        DB::table('user_sessions')->whereIn('id', $ids)->update(['remote_lock_at' => now()]);

        return $this->apiSuccess(['locked' => count($ids)], 'Lock command sent.');
    }

    public function lockOthers(Request $request): JsonResponse
    {
        if (!$this->hasLockCol()) {
            return $this->apiError($this->ar($request) ? 'الميزة دي محتاجة تحديث قاعدة البيانات.' : 'This needs a database update.', null, 503);
        }
        $uid = $this->uid($request);
        $cur = $this->sid($request);
        $mine = $cur > 0 ? ($this->deviceSessionIds($uid, $cur) ?: [$cur]) : [];
        $ids = array_values(array_diff($this->activeRows($uid)->pluck('id')->map(fn ($v) => (int) $v)->all(), $mine));
        if ($ids) {
            DB::table('user_sessions')->whereIn('id', $ids)->update(['remote_lock_at' => now()]);
        }

        return $this->apiSuccess(['locked' => count($ids)], 'Lock command sent.');
    }

    /**
     * نبضة الجهاز (كل ~20 ث من سطح المكتب): أمر قفل عن بُعد (بيتسلّم مرة واحدة) + الإعدادات الحالية
     * (عشان تغيير من جهاز تاني يوصل) + عدد الأجهزة المفتوحة.
     * الخروج بعد الخمول مش هنا: الفرونت بيفحصه بنفسه من آخر تفاعل حقيقي (AuthContext)، والسيرفر بيطبّقه
     * كخط دفاع تاني في UserSessionService::idleExpired.
     */
    public function pulse(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $sid = $this->sid($request);
        $lock = false;
        if ($sid > 0 && $this->hasLockCol()) {
            // atomic: أمر القفل بيتسلّم مرة واحدة بس
            $lock = DB::table('user_sessions')->where('id', $sid)->whereNotNull('remote_lock_at')->update(['remote_lock_at' => null]) > 0;
        }

        return $this->apiSuccess([
            'lock'     => $lock,
            'settings' => $this->security->cached($uid),
            'devices'  => count($this->devices($uid, $sid)),
        ], 'ok')->header('Cache-Control', 'no-store');
    }
}
