<?php

namespace App\Http\Middleware;

use App\Services\CountryRestrictionPolicyService;
use App\Services\IpRestrictionPolicyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IP Restrictions + Country Restrictions (Security > Policies > Network access).
 *
 * قبل كده الاتنين كانوا بيتخزّنوا بس ومفيش أي كود بينادي isAllowed()/evaluate()،
 * فالسياسة كانت شكلية. دلوقتي بتتطبق على كل ريكوست /api/* (مش اللوجين بس)
 * بنفس فلسفة UipDeviceRestrictionMiddleware، فأي IP أو دولة ممنوعة بتتردّ 403
 * قبل ما توصل لأي controller.
 *
 * مستثنى: OPTIONS (CORS preflight) و GET /api/v1/device-status.
 * الترتيب: بعد UipTrustedClientIpMiddleware (عشان $request->ip() يبقى الحقيقي) وبعد Cors.
 */
class UipNetworkRestrictionMiddleware
{
    public function __construct(
        private IpRestrictionPolicyService $ipPolicy,
        private CountryRestrictionPolicyService $countryPolicy
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS') || $request->is('api/v1/device-status')) {
            return $next($request);
        }

        $ip = (string) $request->ip();
        $ar = $request->header('X-Locale', 'en') === 'ar';

        if (!$this->ipPolicy->isAllowed($ip)) {
            return $this->deny(
                $ar ? 'الدخول للمنصة من عنوان الـ IP ده غير مسموح.' : 'Access to the platform from this IP address is not allowed.',
                'ip_blocked',
                ['ip_blocked' => true]
            );
        }

        $country = $this->countryPolicy->evaluate($ip);
        if (!$country['allowed']) {
            return $this->deny(
                $ar ? 'الدخول للمنصة من بلدك غير مسموح حاليًا.' : 'Access to the platform from your country is not allowed.',
                'country_blocked',
                ['country_blocked' => true, 'country_code' => $country['country_code'], 'reason' => $country['reason']]
            );
        }

        return $next($request);
    }

    private function deny(string $message, string $code, array $data): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => $data,
            'errors'  => ['code' => $code],
            'meta'    => (object) [],
        ], 403)->header('Cache-Control', 'no-store');
    }
}
