<?php

namespace App\Http\Middleware;

use App\Services\BlockedDeviceService;
use App\Services\DeviceRestrictionPolicyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Device Restrictions Policy — على كل ريكوست /api/* (مش بس اللوجين).
 *
 * قبل كده السياسة كانت بتتفحص في Login / Refresh / uip.auth بس، فكل الـ endpoints
 * العامة (register, forgot-password, verify-email, public/*, universities…) كانت
 * شغالة عادي من نوع جهاز محظور — وده كان سبب إن "إنشاء حساب" بيشتغل من الموبايل
 * رغم إن اللوجين مقفول. دلوقتي أي ريكوست من نوع جهاز محظور بيتردّ 403 device_blocked
 * قبل ما يوصل لأي controller.
 *
 * مستثنى بس:
 *   - OPTIONS (CORS preflight)
 *   - GET /api/v1/device-status — الفرونت بيسأل بيه "أنا محظور؟" عشان يعرض صفحة الحظر.
 *
 * ⚠️ الترتيب: لازم يتسجّل في bootstrap/app.php بعد UipCorsMiddleware (عشان رد الـ 403
 * يطلع بهيدرز CORS) — شوف ملحوظة bootstrap/app.php.
 */
class UipDeviceRestrictionMiddleware
{
    public function __construct(private DeviceRestrictionPolicyService $policy, private BlockedDeviceService $blocked)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS') || $request->is('api/v1/device-status')) {
            return $next($request);
        }

        $locale = $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';

        // جهاز محظور يدويًا من /admin/mobile (X-Device-Id). نفس شكل رد device_blocked عشان DeviceGate يعرض صفحة الحظر.
        if ($this->blocked->isBlocked($request->header('X-Device-Id'))) {
            $msg = $locale === 'ar' ? 'تم حظر هذا الجهاز من قبل إدارة المنصة.' : 'This device has been blocked by the platform administrators.';
            return response()->json([
                'success' => false,
                'message' => $msg,
                'data'    => ['device_blocked' => true, 'device_type' => 'banned'],
                'errors'  => ['code' => 'device_blocked'],
                'meta'    => (object) [],
            ], 403)->header('Cache-Control', 'no-store');
        }

        if ($this->policy->isAllowed($request->userAgent())) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => $this->policy->blockedMessage($locale),
            'data'    => [
                'device_blocked' => true,
                'device_type'    => $this->policy->classify($request->userAgent()),
            ],
            'errors'  => ['code' => 'device_blocked'],
            'meta'    => (object) [],
        ], 403)->header('Cache-Control', 'no-store');
    }
}
