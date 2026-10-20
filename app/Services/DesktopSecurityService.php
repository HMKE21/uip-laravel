<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إعدادات الأمان الشخصية لسطح المكتب ("جهازي ▸ الإعدادات ▸ الأمان والأجهزة"):
 * بتتخزن JSON في desktop_settings.security، والسيرفر بيطبّق منها فعليًا:
 *   maxDevices / deviceOverflow  → UserSessionService::enforceDeviceLimit + loginBlock
 *   autoLogoutMinutes            → UserSessionService::idleExpired (خمول الحساب كله)
 * والباقي (القفل التلقائي، السكون، القفل عند مغادرة التبويب…) بيطبّقه سطح المكتب نفسه على الجهاز.
 * الحدود دايمًا "الأشد يكسب": اختيار المستخدم مينفعش يفتح أكتر من اللي الأدمن سامح بيه، بس يقدر يشدّد.
 */
class DesktopSecurityService
{
    /** الخيارات المسموحة (بالدقايق) — نفس القوائم اللي الفرونت بيعرضها. */
    public const AUTO_LOCK = [0, 1, 2, 3, 5, 10, 15, 30, 60];
    public const SLEEP = [0, 2, 5, 10, 15, 30, 60];
    public const AUTO_LOGOUT = [0, 15, 30, 60, 120, 240, 480, 1440, 4320, 10080];
    public const LOCK_ON_HIDE = [-1, 0, 1, 5, 15]; // -1 = إيقاف، 0 = فورًا، وإلا دقايق
    public const OVERFLOW = ['replace_oldest', 'block'];
    public const MAX_DEVICES_CHOICE = 20;

    public const DEFAULTS = [
        'maxDevices'         => 0,                 // 0 = اللي سياسة المنصة بتسمح بيه
        'deviceOverflow'     => 'replace_oldest',  // لما الحد يتوصّل: اخرج أقدم جهاز | امنع الدخول الجديد
        'autoLockMinutes'    => 0,                 // 0 = أبدًا
        'sleepMinutes'       => 0,
        'lockOnHide'         => -1,
        'lockOnStart'        => true,              // جهاز/تبويب جديد يبدأ مقفول (لو فيه باسورد قفل)
        'lockAfterSleep'     => true,              // يطلب الباسورد بعد السكون
        'autoLogoutMinutes'  => 0,                 // 0 = أبدًا (خروج الحساب بعد خمول)
        'alertNewDevice'     => true,
        'hideLockDetails'    => false,             // شاشة القفل متعرضش الإيميل
        'sleepDimOnly'       => false,             // السكون يعتّم الشاشة بس بدل الشاشة السودا
    ];

    private function hasColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Schema::hasTable('desktop_settings') && Schema::hasColumn('desktop_settings', 'security');
            } catch (\Throwable) {
                $has = false;
            }
        }

        return $has;
    }

    private static function pick($v, array $allowed, $default)
    {
        return (is_numeric($v) && in_array((int) $v, $allowed, true)) ? (int) $v : $default;
    }

    /** يطبّع أي مدخلات لشكل سليم (قيم غلط => الافتراضي). */
    public function normalize(array $in, array $base = self::DEFAULTS): array
    {
        $out = $base + self::DEFAULTS;
        $out['maxDevices'] = array_key_exists('maxDevices', $in)
            ? max(0, min(self::MAX_DEVICES_CHOICE, (int) $in['maxDevices'])) : (int) $out['maxDevices'];
        if (array_key_exists('deviceOverflow', $in)) {
            $out['deviceOverflow'] = in_array($in['deviceOverflow'], self::OVERFLOW, true) ? $in['deviceOverflow'] : self::DEFAULTS['deviceOverflow'];
        }
        foreach ([
            'autoLockMinutes' => self::AUTO_LOCK, 'sleepMinutes' => self::SLEEP,
            'autoLogoutMinutes' => self::AUTO_LOGOUT, 'lockOnHide' => self::LOCK_ON_HIDE,
        ] as $k => $allowed) {
            if (array_key_exists($k, $in)) {
                $out[$k] = self::pick($in[$k], $allowed, self::DEFAULTS[$k]);
            }
        }
        foreach (['lockOnStart', 'lockAfterSleep', 'alertNewDevice', 'hideLockDetails', 'sleepDimOnly'] as $k) {
            if (array_key_exists($k, $in)) {
                $out[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        // لو الاتنين شغّالين، السكون بعد القفل التلقائي مش قبله (أقل مفاجآت للمستخدم).
        if ($out['autoLockMinutes'] > 0 && $out['sleepMinutes'] > 0 && $out['sleepMinutes'] < $out['autoLockMinutes']) {
            $out['sleepMinutes'] = $out['autoLockMinutes'];
            if (!in_array($out['sleepMinutes'], self::SLEEP, true)) {
                foreach (self::SLEEP as $s) {
                    if ($s >= $out['autoLockMinutes']) {
                        $out['sleepMinutes'] = $s;
                        break;
                    }
                }
            }
        }

        return array_intersect_key($out, self::DEFAULTS);
    }

    public function get(int $userId): array
    {
        if (!$this->hasColumn()) {
            return self::DEFAULTS;
        }
        $raw = DB::table('desktop_settings')->where('user_id', $userId)->value('security');
        $dec = is_string($raw) ? json_decode($raw, true) : null;

        return $this->normalize(is_array($dec) ? $dec : []);
    }

    public function save(int $userId, array $input): array
    {
        $cur = $this->get($userId);
        $new = $this->normalize($input, $cur);
        if (!$this->hasColumn()) {
            throw new \RuntimeException('Security settings are not available until the database is migrated.');
        }
        DB::table('desktop_settings')->insertOrIgnore(['user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('desktop_settings')->where('user_id', $userId)->update(['security' => json_encode($new), 'updated_at' => now()]);
        Cache::forget($this->cacheKey($userId));

        return $new;
    }

    private function cacheKey(int $userId): string
    {
        return 'desk.sec.' . $userId;
    }

    /** نسخة متكاشّة 60 ثانية — بتتسأل من الـ middleware / login / refresh. */
    public function cached(int $userId): array
    {
        try {
            return Cache::remember($this->cacheKey($userId), 60, fn () => $this->get($userId));
        } catch (\Throwable) {
            return self::DEFAULTS;
        }
    }

    /** أصغر رقم موجب (0 = مفيش حد). */
    public static function stricter(int $a, int $b): int
    {
        if ($a < 1) {
            return max(0, $b);
        }
        if ($b < 1) {
            return $a;
        }

        return min($a, $b);
    }
}
