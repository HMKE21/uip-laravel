<?php

namespace App\Http\Middleware;

use App\Services\ApiRateLimitPolicyService;
use App\Services\UipJwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * بديل لـ alias الـ `throttle` الافتراضي بتاع لارافيل (بيتسجل في bootstrap/app.php).
 *
 * المشكلتين اللي كانوا بيطلّعوا "محاولات كتير. استنى لحظة وحاول تاني" من غير ما المستخدم يعمل حاجة:
 *
 * 1) throttle:N,1 (من غير اسم) مفتاحه = sha1(domain|ip) بس — يعني كل الـ ~270 روت اللي عليهم throttle بيتشاركوا
 *    عدّاد واحد لكل IP (والـ IP ممكن يكون IP الـ proxy أو واي فاي الجامعة)، فروت حدّه 5 أو 10 بيتقفل بسبب
 *    طلبات روتات تانية. دلوقتي المفتاح = (روت + HTTP method + المستخدم/الجهاز)، فكل روت ليه عدّاد مستقل
 *    لكل شخص.
 *
 * 2) إيقاف الـ API Rate Limit من داشبورد الأدمن (Security > Policies) كان بيوقف UipRateLimitMiddleware العام بس،
 *    وروتات الـ throttle:N,1 فضلت شغالة. دلوقتي لو السياسة enabled=false بنتخطّى كل الـ throttles
 *    ماعدا auth-login (حماية تخمين الباسورد؛ قفل الحساب شغال برضه).
 */
class UipThrottleRequests extends ThrottleRequests
{
    /** Limiters that stay active even when the admin disables the API rate limit (brute-force protection). */
    private const ALWAYS_ON = ['auth-login'];

    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        if (!in_array((string) $maxAttempts, self::ALWAYS_ON, true) && $this->policyDisabled()) {
            return $next($request);
        }

        // مهم: لارافيل بيفرّق بين throttle:60,1 (رقم) و throttle:auth-login (limiter مسمّى) بـ func_num_args() === 3.
        // لو مرّرنا الـ 5 arguments دايمًا الـ limiter المسمّى بيتعامل كرقم ← "Rate limiter [x] is not defined" (500).
        // فبنمرّر بالظبط الـ arguments اللي اتبعتت لنا.
        return parent::handle(...func_get_args());
    }

    /** Per route + method + (user | device) instead of one IP-wide counter shared by every route. */
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $routeKey = $route ? ($route->getDomain() . '|' . $route->uri()) : $request->path();

        return sha1(implode('|', [$request->method(), $routeKey, $this->actor($request)]));
    }

    private function actor(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            $claims = UipJwtService::decodeAccess(substr($header, 7));
            if ($claims && !empty($claims['sub'])) {
                return 'user_' . $claims['sub'];
            }
        }

        return 'anon_' . sha1(implode('|', [
            (string) $request->ip(),
            (string) $request->userAgent(),
            (string) $request->header('Accept-Language', ''),
        ]));
    }

    private function policyDisabled(): bool
    {
        try {
            return !app(ApiRateLimitPolicyService::class)->getPolicy()['enabled'];
        } catch (\Throwable $e) {
            return false;
        }
    }
}
