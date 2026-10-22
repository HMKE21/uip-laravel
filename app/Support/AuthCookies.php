<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * الـ refresh token في كوكي HttpOnly بدل localStorage — فأي XSS مبقاش يقدر
 * يسرق credential عمره 14 يوم (الـ JS مبيشوفوش أصلًا).
 *
 * - uip_refresh : HttpOnly + Secure + SameSite، Path=/api/v1/auth بس (المتصفح
 *                 مبيبعتوش غير لـ refresh-token / logout / login...).
 * - uip_csrf    : مقروء من JS (مش HttpOnly). قيمته = HMAC(refresh token) فهو
 *                 ملزوق بالـ refresh token نفسه — "signed double-submit":
 *                 الطلب لازم يبعت X-CSRF-Token بنفس القيمة، والسيرفر يتأكد إنها
 *                 مطابقة للكوكي *و*لـ HMAC الـ refresh token اللي جاي في الكوكي.
 *                 يعني حتى لو حد زرع كوكي csrf من subdomain تاني مش هينفع.
 *
 * الـ access token لسه Bearer في الـ header (مفيش CSRF عليه).
 *
 * ENV:
 *   AUTH_REFRESH_TRANSPORT = cookie (افتراضي) | both
 *       both = كمان يرجّع refresh_token في جسم الرد (لعملاء مش متصفح فقط).
 *   AUTH_COOKIE_SAMESITE   = strict (افتراضي) | lax | none
 *       none = لما الـ API على دومين تاني عن الـ SPA (محتاج CORS credentials).
 *   AUTH_COOKIE_SECURE     = true/false (الافتراضي: true على https/production)
 *   AUTH_COOKIE_DOMAIN     = فاضي (host-only، الأأمن)
 *   AUTH_ALLOW_BODY_REFRESH= true (افتراضي) — يقبل refresh_token في الجسم عشان
 *       الجلسات القديمة اللي كانت في localStorage تتهاجر للكوكي. اقفله (false)
 *       بعد ما JWT_REFRESH_TTL (14 يوم) يعدي من النشر.
 *   AUTH_ALLOWED_ORIGINS   = origins إضافية مسموحة (CORS_ALLOWED_ORIGINS بتتحسب كمان).
 */
class AuthCookies
{
    public const REFRESH_COOKIE = 'uip_refresh';
    public const CSRF_COOKIE    = 'uip_csrf';
    public const CSRF_HEADER    = 'X-CSRF-Token';
    public const COOKIE_PATH    = '/api/v1/auth';

    /** بصمة المتصفح (HttpOnly، سنة، بتتجدد): مش credential — بس بتعرّف إن اللي بيدخل هو نفس المتصفح بتاع الجلسة الحية. */
    public const DEVICE_COOKIE  = 'uip_did';

    // ---- القراءة ---------------------------------------------------------

    public static function refreshFromCookie(Request $request): string
    {
        // نقرا الكوكي الخام (الـ api group مفيهوش EncryptCookies).
        $value = $request->cookies->get(self::REFRESH_COOKIE);
        return is_string($value) ? $value : '';
    }

    /**
     * معرّف المتصفح. $create=true بيطلّع واحد جديد لو مفيش (وبيفتكره على الـ request عشان
     * attach() يحطه في الكوكي في نفس الرد). $create=false بيرجّع '' لو مفيش كوكي صالح.
     */
    public static function deviceId(Request $request, bool $create = false): string
    {
        $value = $request->cookies->get(self::DEVICE_COOKIE);
        if (is_string($value) && preg_match('/^[a-f0-9]{48}$/', $value) === 1) {
            return $value;
        }
        $minted = $request->attributes->get('uip_device_id');
        if (is_string($minted) && $minted !== '') {
            return $minted;
        }
        if (!$create) {
            return '';
        }
        $id = bin2hex(random_bytes(24));
        $request->attributes->set('uip_device_id', $id);
        return $id;
    }

    public static function allowBodyRefresh(): bool
    {
        return (bool) config('security.cookie.allow_body_refresh', true);
    }

    public static function exposeRefreshInBody(): bool
    {
        return strtolower((string) config('security.cookie.refresh_transport', 'cookie')) === 'both';
    }

    // ---- CSRF ------------------------------------------------------------

    public static function csrfFor(string $rawRefresh): string
    {
        return hash_hmac('sha256', 'uip-csrf|' . $rawRefresh, self::secret());
    }

    /**
     * يرجّع null لو الطلب سليم، أو كود سبب الرفض.
     * بيتنادى بس لما الـ refresh token جاي من الكوكي.
     */
    public static function csrfFailure(Request $request): ?string
    {
        $raw = self::refreshFromCookie($request);
        if ($raw === '') {
            return 'csrf_no_cookie';
        }

        $header = (string) $request->header(self::CSRF_HEADER, '');
        $cookie = (string) $request->cookies->get(self::CSRF_COOKIE, '');
        if ($header === '' || $cookie === '') {
            return 'csrf_missing';
        }

        $expected = self::csrfFor($raw);
        if (!hash_equals($expected, $header) || !hash_equals($expected, $cookie)) {
            return 'csrf_mismatch';
        }

        // طبقة زيادة: لو الـ Origin اتبعت ومعانا allowlist، لازم يكون منها.
        $origin = (string) $request->headers->get('Origin', '');
        $allowed = self::allowedOrigins();
        if ($origin !== '' && !empty($allowed) && !in_array(rtrim($origin, '/'), $allowed, true)) {
            return 'origin_not_allowed';
        }

        return null;
    }

    /** @return string[] origins صريحة (من غير wildcard). فاضية = مفيش allowlist مظبوطة. */
    public static function allowedOrigins(): array
    {
        $list = [];
        foreach (['security.cors_allowed_origins', 'security.auth_allowed_origins'] as $key) {
            foreach (explode(',', (string) config($key, '')) as $o) {
                $o = rtrim(trim($o), '/');
                if ($o !== '' && $o !== '*') {
                    $list[] = $o;
                }
            }
        }
        $front = rtrim(trim((string) config('app.frontend_url', '')), '/');
        if ($front !== '' && !empty($list)) {
            $list[] = $front;
        }
        return array_values(array_unique($list));
    }

    // ---- الإصدار / المسح ---------------------------------------------------

    /**
     * لو الرد فيه data.refresh_token: يحطه في كوكي HttpOnly ويحط كوكي الـ CSRF،
     * ويشيل التوكن من الجسم (إلا لو AUTH_REFRESH_TRANSPORT=both).
     */
    public static function attach(Response $response, Request $request): Response
    {
        $response = self::noStore($response);

        if (!$response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(); // objects — عشان meta: {} ميتحولش لـ []
        $raw = $payload->data->refresh_token ?? null;
        if (!is_object($payload) || !is_string($raw) || $raw === '') {
            return $response;
        }

        $ttl = \App\Services\UipJwtService::refreshTtl();
        $expires = time() + $ttl;

        $response->headers->setCookie(self::makeCookie(self::REFRESH_COOKIE, $raw, $expires, self::COOKIE_PATH, true, $request));
        $response->headers->setCookie(self::makeCookie(self::CSRF_COOKIE, self::csrfFor($raw), $expires, '/', false, $request));
        // بصمة المتصفح: بتفضل بعد الـ logout (clear() مبيلمسهاش) وبتتجدد مع كل إصدار توكن.
        $response->headers->setCookie(self::makeCookie(self::DEVICE_COOKIE, self::deviceId($request, true), time() + 31536000, self::COOKIE_PATH, true, $request));

        if (!self::exposeRefreshInBody()) {
            unset($payload->data->refresh_token);
            $response->setData($payload);
        }

        return $response;
    }

    public static function clear(Response $response, Request $request): Response
    {
        $response = self::noStore($response);
        $response->headers->setCookie(self::makeCookie(self::REFRESH_COOKIE, '', 1, self::COOKIE_PATH, true, $request));
        $response->headers->setCookie(self::makeCookie(self::CSRF_COOKIE, '', 1, '/', false, $request));
        return $response;
    }

    public static function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');
        return $response;
    }

    // ---- داخلي -----------------------------------------------------------

    private static function makeCookie(string $name, string $value, int $expires, string $path, bool $httpOnly, Request $request): Cookie
    {
        $sameSite = strtolower((string) config('security.cookie.samesite', 'strict'));
        if (!in_array($sameSite, ['strict', 'lax', 'none'], true)) {
            $sameSite = 'strict';
        }

        $secureEnv = config('security.cookie.secure');
        $secure = $secureEnv !== null && $secureEnv !== ''
            ? filter_var($secureEnv, FILTER_VALIDATE_BOOLEAN)
            : ($request->isSecure() || app()->environment('production'));

        // SameSite=None من غير Secure المتصفح بيرفضه.
        if ($sameSite === 'none') {
            $secure = true;
        }

        $domain = trim((string) config('security.cookie.domain', ''));

        return new Cookie(
            $name,
            $value,
            $expires,
            $path,
            $domain !== '' ? $domain : null,
            $secure,
            $httpOnly,
            false,
            $sameSite
        );
    }

    private static function secret(): string
    {
        $key = (string) config('app.key', '');
        if ($key === '') {
            $key = (string) config('security.jwt.secret', '');
        }
        if ($key === '') {
            throw new \RuntimeException('APP_KEY or JWT_SECRET must be set to issue auth cookies.');
        }
        return $key;
    }
}
