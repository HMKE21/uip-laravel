<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * حالة صحة كل مزوّد SFU (circuit breaker) — مشتركة بين كل الـ requests والـ workers عن طريق الـ Cache.
 *
 *   closed  : كله تمام، الطلبات بتعدّي.
 *   open    : `fail_threshold` فشل متتالي → المزوّد "واقع" لحد down_until. الطلبات بتفشل فورًا (من غير ما تستنى timeout)
 *             والغرف اللي شغالة عليه بتتحول للمزوّد التاني (MeetingTransportService).
 *   half-open: بعد down_until الطلبات بتعدّي تاني؛ أول فشل بيفتح الدايرة بمدة أطول (exponential backoff)، وأول نجاح بيقفلها.
 *
 * بنحسب فشل السيرفر بس (مفيش اتصال / 502 / 503 / 504 / 401 / 403 / 408 / 429). أي 4xx تاني أو 500 = السيرفر شغال
 * (ممكن يكون خطأ في باراميترز عميل واحد)، فمايتحسبش فشل ومايقدرش مستخدم يوقّع المزوّد على الكل.
 * القراءة/الكتابة من غير lock: العدّاد تقريبي (سباق نادر بيأخّر الفتح طلب واحد)، وده أفضل من lock على كل طلب ميديا.
 */
class SfuHealth
{
    public const PROVIDERS = ['cloudflare', 'selfhosted'];

    private const TTL = 86400;

    /** هل رد الـ HTTP ده معناه إن المزوّد نفسه تعبان (مش إن الطلب غلط)؟ */
    public static function failureStatus(int $status): bool
    {
        return $status >= 502 || in_array($status, [401, 403, 408, 429], true);
    }

    private static function key(string $provider): string
    {
        return 'sfu_health:' . $provider;
    }

    /** @return array{fails:int,down_until:int,opened_at:int,last_ok:int,last_fail:int,last_error:string,probed_at:int} */
    public static function state(string $provider): array
    {
        $s = Cache::get(self::key($provider));

        return (is_array($s) ? $s : []) + [
            'fails' => 0, 'down_until' => 0, 'opened_at' => 0, 'last_ok' => 0, 'last_fail' => 0, 'last_error' => '', 'probed_at' => 0,
        ];
    }

    private static function save(string $provider, array $s): void
    {
        Cache::put(self::key($provider), $s, self::TTL);
    }

    private static function cfg(string $key, int $default): int
    {
        return max(0, (int) config('webrtc.failover.' . $key, $default));
    }

    public static function isDown(string $provider, ?int $now = null): bool
    {
        return ($now ?? time()) < (int) self::state($provider)['down_until'];
    }

    public static function recordSuccess(string $provider, bool $probe = false): void
    {
        $s = self::state($provider);
        $now = time();
        $wasOpen = (int) $s['opened_at'] > 0;
        // مفيش داعي نكتب في الـ cache مع كل طلب ناجح: بنكتب بس لو في حاجة اتغيّرت (أو كل 10 ثواني).
        if (!$wasOpen && (int) $s['fails'] === 0 && ($now - (int) $s['last_ok']) < 10 && !$probe) {
            return;
        }
        $s['fails'] = 0;
        $s['down_until'] = 0;
        $s['opened_at'] = 0;
        $s['last_ok'] = $now;
        $s['last_error'] = '';
        if ($probe) {
            $s['probed_at'] = $now;
        }
        self::save($provider, $s);
        if ($wasOpen) {
            Log::info('SFU provider recovered', ['provider' => $provider]);
        }
    }

    public static function recordFailure(string $provider, string $reason, bool $probe = false): void
    {
        $s = self::state($provider);
        $now = time();
        $s['fails'] = (int) $s['fails'] + 1;
        $s['last_fail'] = $now;
        $s['last_error'] = mb_substr($reason, 0, 200);
        if ($probe) {
            $s['probed_at'] = $now;
        }

        $threshold = max(1, self::cfg('fail_threshold', 3));
        if ($s['fails'] >= $threshold) {
            $over = min(6, $s['fails'] - $threshold);
            $cool = min(max(1, self::cfg('max_cooldown_seconds', 300)), max(1, self::cfg('cooldown_seconds', 30)) * (2 ** $over));
            $firstOpen = (int) $s['opened_at'] === 0;
            $s['down_until'] = $now + $cool;
            $s['opened_at'] = $firstOpen ? $now : (int) $s['opened_at'];
            if ($firstOpen) {
                Log::warning('SFU provider marked DOWN (failover may trigger)', ['provider' => $provider, 'fails' => $s['fails'], 'cooldown' => $cool, 'reason' => $s['last_error']]);
            }
        }
        self::save($provider, $s);
    }

    /** ملخص للأدمن (/sfu/health). */
    public static function snapshot(string $provider): array
    {
        $s = self::state($provider);
        $now = time();
        $stale = max(1, self::cfg('stale_seconds', 180));
        $lastCheck = max((int) $s['probed_at'], (int) $s['last_ok'], (int) $s['last_fail']);

        return [
            'down'             => $now < (int) $s['down_until'],
            'down_for_seconds' => max(0, (int) $s['down_until'] - $now),
            'consecutive_fails' => (int) $s['fails'],
            'last_ok_at'       => $s['last_ok'] ?: null,
            'last_fail_at'     => $s['last_fail'] ?: null,
            'last_error'       => $s['last_error'] !== '' ? $s['last_error'] : null,
            'last_probe_at'    => $s['probed_at'] ?: null,
            'stale'            => $lastCheck === 0 || ($now - $lastCheck) > $stale,
        ];
    }

    /** للاختبارات/الأدمن: امسح حالة مزوّد. */
    public static function reset(string $provider): void
    {
        Cache::forget(self::key($provider));
    }
}
