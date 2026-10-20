<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * نسخة طبق الأصل من TrustedDeviceService القديمة — نفس selector:validator
 * pattern بالظبط (نفس التخزين، نفس الـ hash_equals). الفرق الوحيد: بيستخدم
 * Laravel Cookie facade بدل setcookie()/$_COOKIE مباشرة — القيمة والسلوك
 * (httponly, 30 يوم) بيطابقوا القديم بالظبط.
 */
class TrustedDeviceService
{
    public const COOKIE_NAME = 'uip_trusted_device';
    private const TTL_DAYS = 30;

    /**
     * بيسجّل الجهاز ويرجّع الكوكي — **لازم** المنادي يحطها على الرد بإيده
     * ($response->headers->setCookie($cookie)). كنا بنستخدم Cookie::queue() بس ده بيحتاج
     * AddQueuedCookiesToResponse (موجود في web group بس)، والـ API هنا من غير web group،
     * فالكوكي كانت بتضيع ومبتوصلش للمتصفح أبدًا = الجهاز مبيتحفظش.
     */
    public function trustCurrentDevice(int $userId, string $ip, ?string $userAgent): Cookie
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));

        TrustedDevice::create([
            'user_id'      => $userId,
            'selector'     => $selector,
            'token_hash'   => hash('sha256', $validator),
            'device_label' => $this->deviceLabelFrom($userAgent),
            'user_agent'   => $userAgent,
            'ip_address'   => $ip,
            'last_used_at' => now(),
            'expires_at'   => now()->addDays(self::TTL_DAYS),
        ]);

        $sameSite = strtolower((string) env('AUTH_COOKIE_SAMESITE', 'lax'));
        if (!in_array($sameSite, ['strict', 'lax', 'none'], true)) {
            $sameSite = 'lax';
        }
        $secure = app()->environment('production') || (bool) config('session.secure', false) || $sameSite === 'none';
        $domain = trim((string) env('AUTH_COOKIE_DOMAIN', ''));

        $cookie = new Cookie(
            self::COOKIE_NAME,
            $selector . ':' . $validator,
            time() + self::TTL_DAYS * 86400,
            '/',
            $domain !== '' ? $domain : null,
            $secure,
            true,   // HttpOnly
            false,
            $sameSite
        );

        SecurityLog::write('Device remembered for 2FA', ['user_id' => $userId, 'ip' => $ip]);
        Log::channel(config('logging.default'))->info('Device remembered for 2FA', ['user_id' => $userId, 'ip' => $ip]);

        return $cookie;
    }

    /** True لو الكوكي بتاعة الريكوست الحالي trusted device صالح لنفس المستخدم ده. Best-effort دايمًا. */
    public function isCurrentDeviceTrusted(int $userId, string $ip, ?string $rawCookie): bool
    {
        if (!$rawCookie || !str_contains($rawCookie, ':')) {
            return false;
        }

        [$selector, $validator] = explode(':', $rawCookie, 2);
        if ($selector === '' || $validator === '') {
            return false;
        }

        $device = TrustedDevice::where('selector', $selector)->first();
        if (!$device || $device->revoked_at || (int) $device->user_id !== $userId) {
            return false;
        }

        if ($device->expires_at && $device->expires_at->isPast()) {
            return false;
        }

        if (!hash_equals((string) $device->token_hash, hash('sha256', $validator))) {
            SecurityLog::write('Trusted device validator mismatch', ['user_id' => $userId, 'ip' => $ip]);
            Log::warning('Trusted device validator mismatch', ['user_id' => $userId, 'ip' => $ip]);
            return false;
        }

        $device->last_used_at = now();
        $device->ip_address = $ip;
        $device->save();

        return true;
    }

    /** Revokes كل الـ trusted devices بتاعة مستخدم — بتتنادى لو 2FA اتقفل. */
    public function revokeAllForUser(int $userId): void
    {
        TrustedDevice::where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /**
     * منقولة من TrustedDeviceRepository::activeForUser() القديمة — بند
     * 25 batch 6 (Settings). @return array<int,array<string,mixed>>
     * أجهزة المستخدم النشطة (مش revoked، ولسه ما expired)، الأحدث
     * استخدامًا أولًا، لصفحة Settings الذاتية.
     */
    public function listForUser(int $userId): array
    {
        return TrustedDevice::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (TrustedDevice $d) => $d->toArray())
            ->all();
    }

    /**
     * منقولة من TrustedDeviceRepository::revokeForUser() القديمة — بند
     * 25 batch 6. Self-service "forget this device"، مُنطاقة بـ
     * $userId عشان المستخدم يقدر يلغي أجهزته بس.
     */
    public function revoke($deviceId, int $userId): bool
    {
        $device = TrustedDevice::where('id', $deviceId)->where('user_id', $userId)->first();
        if (!$device || $device->revoked_at) {
            return false;
        }
        $device->revoked_at = now();
        return $device->save();
    }

    private function deviceLabelFrom(?string $userAgent): ?string
    {
        if (!$userAgent) {
            return null;
        }
        $browser = preg_match('/Edg\//', $userAgent) ? 'Edge'
            : (preg_match('/Chrome\//', $userAgent) ? 'Chrome'
            : (preg_match('/Firefox\//', $userAgent) ? 'Firefox'
            : (preg_match('/Safari\//', $userAgent) ? 'Safari' : 'Unknown Browser')));
        $os = preg_match('/Windows/', $userAgent) ? 'Windows'
            : (preg_match('/Mac OS/', $userAgent) ? 'macOS'
            : (preg_match('/Android/', $userAgent) ? 'Android'
            : (preg_match('/iPhone|iPad/', $userAgent) ? 'iOS'
            : (preg_match('/Linux/', $userAgent) ? 'Linux' : 'Unknown OS'))));
        return "{$browser} on {$os}";
    }
}
