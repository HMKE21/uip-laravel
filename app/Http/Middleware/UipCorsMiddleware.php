<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * نسخة طبق الأصل من app/Middleware/CorsMiddleware.php القديمة: بترجّع
 * الـ Origin بتاع الريكوست نفسه (مقيّد اختياريًا بـ CORS_ALLOWED_ORIGINS)
 * بدل قيمة ثابتة واحدة، عشان الـ API يشتغل من أي origin مسموح بيه (dev
 * وprod) مش بس APP_URL. هنا كمان Bearer JWT بس (مفيش cookies) فمفيش
 * تعارض credentialed-request/wildcard زي ما كان موثّق في القديم.
 *
 * ⚠️ فرق تسجيل متعمّد عن القديم: القديم كان بيقفل الـ preflight (OPTIONS)
 * في core/App.php *قبل* الـ Router عشان الـ Router بتاعه مكنش بيسجل
 * OPTIONS أصلًا. هنا مسجّلة كـ Global middleware في bootstrap/app.php
 * (مش route middleware) بالظبط لنفس السبب: أي OPTIONS لروت مش مسجّل هيوصل
 * لغاية هنا قبل ما لارافيل يرمي 404، فهي بتقفله بنفس الطريقة. شوف
 * README.md قسم "تسجيل الـ Middleware" للتفاصيل.
 */
class UipCorsMiddleware
{
    /** Origins بتاعة تطبيق الأندرويد/iOS (Capacitor). */
    private const NATIVE_APP_ORIGINS = [
        'https://localhost',
        'http://localhost',
        'capacitor://localhost',
        'ionic://localhost',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->attachHeaders(response('', 204), $request);
        }

        $response = $next($request);

        return $this->attachHeaders($response, $request);
    }

    private function attachHeaders(Response $response, Request $request): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $this->resolveAllowedOrigin($request));
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Exam-Session, X-Device-Id, X-Enc-Kid, X-Enc-Z, X-App-Token, X-UIP-Client, X-UIP-Page, X-UIP-Activity-Session, X-Locale, X-Share-Session, X-Share-Client, X-Share-Confirm, X-View-Claim, Accept, Accept-Language, If-None-Match');
        // ETag lets the front-end re-validate its local copy of a file (304) instead of downloading it again
        $response->headers->set('Access-Control-Expose-Headers', 'ETag, Content-Disposition');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin');

        // الكوكي HttpOnly محتاج Allow-Credentials لو الـ API على origin تاني (SameSite=None).
        // بنسمح بيه بس لو الـ Origin مكتوب صراحةً في allowlist — أبدًا مع وضع "اعكس أي Origin".
        $origin = $request->header('Origin');
        if ($origin && in_array(rtrim($origin, '/'), \App\Support\AuthCookies::allowedOrigins(), true)) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * - CORS_ALLOWED_ORIGINS فاضي (الافتراضي): بيرجّع أي Origin بعت بيه
     *   الريكوست.
     * - CORS_ALLOWED_ORIGINS="https://app.example.com,http://localhost:5173"
     *   (مفصولة بفاصلة): بيرجّع الـ Origin بس لو موجود في اللستة؛ غير كده
     *   بيرجع config('app.url').
     */
    private function resolveAllowedOrigin(Request $request): string
    {
        $origin = $request->header('Origin');
        $allowList = array_filter(array_map('trim', explode(',', (string) config('security.cors_allowed_origins', ''))));

        if (!$origin) {
            return (string) config('app.url', '*');
        }

        if (empty($allowList)) {
            return $origin;
        }

        // تطبيق الأندرويد (Capacitor) بيشتغل على https://localhost جوه الـ WebView، ورفع الملفات (فيديو/ريكورد/صور)
        // وستريم الـ AI بيروحوا مباشرة من الـ WebView للـ API (cross-origin) فلازم الـ origin ده يتسمح دايمًا
        // حتى لو CORS_ALLOWED_ORIGINS متظبطة على دومين الموقع بس. الـ Credentials بتفضل مقصورة على الـ allowlist الصريحة.
        if (in_array($origin, self::NATIVE_APP_ORIGINS, true)) {
            return $origin;
        }

        return in_array($origin, $allowList, true) ? $origin : (string) config('app.url', '*');
    }
}
