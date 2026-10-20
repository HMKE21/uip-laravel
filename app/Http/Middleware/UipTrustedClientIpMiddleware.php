<?php

namespace App\Http\Middleware;

use App\Support\SecurityLog;
use Closure;
use Illuminate\Http\Request;

/**
 * الـ IP الحقيقي للمستخدم خلف Vercel.
 *
 * المتصفح -> Vercel (rewrite /api/*) -> Railway (Laravel). من غير ده
 * $request->ip() بيرجّع IP سيرفر Vercel (AWS Frankfurt) لكل المستخدمين،
 * فصفحة Sessions والـ GeoIP وعدّادات الدخول الفاشلة وسياسات IP/Country
 * كلها بتشتغل على IP غلط.
 *
 * middleware.js في الفرونت (Vercel Routing Middleware) بيبعت:
 *   X-Uip-Client-Ip  / X-Uip-Proxy-Ts / X-Uip-Proxy-Sig = HMAC_SHA256(secret, "ip|ts")
 * وهنا بنتحقق من التوقيع (بالسر المشترك UIP_PROXY_SECRET)، ومن إن الطابع
 * الزمني في حدود 5 دقايق، ومن إن القيمة IP صالح — وبعدها بس بنخلّي
 * $request->ip() يرجّع الـ IP ده. رابط Railway عام، فأي حد يقدر يبعت الـ
 * headers دي مباشرة؛ من غير السر الصح بتتجاهل ومابتغيّرش أي حاجة.
 *
 * لازم يكون أول middleware في مجموعة api (قبل UipRateLimitMiddleware وغيره
 * اللي بيقرا ip()). لو UIP_PROXY_SECRET مش مضبوط، بيفضل السلوك القديم.
 */
class UipTrustedClientIpMiddleware
{
    private const MAX_SKEW_SECONDS = 300;

    private const HEADERS = ['X-Uip-Client-Ip', 'X-Uip-Proxy-Ts', 'X-Uip-Proxy-Sig'];

    public function handle(Request $request, Closure $next)
    {
        $secret = (string) config('security.proxy_secret', '');
        $ip = (string) $request->headers->get('X-Uip-Client-Ip', '');
        $ts = (string) $request->headers->get('X-Uip-Proxy-Ts', '');
        $sig = strtolower((string) $request->headers->get('X-Uip-Proxy-Sig', ''));

        if ($secret !== '' && ($ip !== '' || $ts !== '' || $sig !== '')) {
            $valid = ctype_digit($ts)
                && abs(time() - (int) $ts) <= self::MAX_SKEW_SECONDS
                && filter_var($ip, FILTER_VALIDATE_IP) !== false
                && $sig !== ''
                && hash_equals(hash_hmac('sha256', $ip . '|' . $ts, $secret), $sig);

            if ($valid) {
                // XFF بنستبدله بالـ IP الموقّع بس؛ باقي سلسلة الثقة (Railway edge) زي ما هي،
                // فـ Symfony بيرجّع الـ IP ده كـ client IP.
                $request->headers->set('X-Forwarded-For', $ip);
            } else {
                SecurityLog::write('Rejected unsigned/invalid client-IP headers', ['peer' => $request->ip()]);
            }
        }

        // الـ headers الداخلية دي ماتوصلش لأي كود تاني.
        foreach (self::HEADERS as $h) {
            $request->headers->remove($h);
        }

        return $next($request);
    }
}
