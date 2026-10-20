<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BlockedDeviceService;
use App\Services\DeviceRestrictionPolicyService;
use Illuminate\Http\Request;

/**
 * GET /api/v1/device-status — عام (من غير auth) ومستثنى من UipDeviceRestrictionMiddleware.
 *
 * الفرونت (DeviceGate) بينادي عليه أول ما المنصة تفتح عشان يعرف لو نوع الجهاز
 * الحالي محظور بالسياسة، فيعرض صفحة "الجهاز ده غير مسموح" بدل ما يفتح المنصة.
 * بيرجّع بس allowed + نوع الجهاز + الرسالة — مش تفاصيل السياسة.
 */
class DeviceStatusController extends Controller
{
    public function __construct(private DeviceRestrictionPolicyService $policy, private BlockedDeviceService $blocked)
    {
    }

    public function show(Request $request)
    {
        $ua      = $request->userAgent();
        $locale  = $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';

        // جهاز محظور يدويًا (X-Device-Id) — نفس رد "مش مسموح" بنوع banned.
        if ($this->blocked->isBlocked($request->header('X-Device-Id'))) {
            $msg = $locale === 'ar' ? 'تم حظر هذا الجهاز من قبل إدارة المنصة.' : 'This device has been blocked by the platform administrators.';
            return response()->json([
                'success' => true,
                'message' => $msg,
                'data'    => ['allowed' => false, 'device_type' => 'banned', 'message' => $msg],
                'meta'    => (object) [],
            ])->header('Cache-Control', 'no-store');
        }

        $allowed = $this->policy->isAllowed($ua);

        return response()->json([
            'success' => true,
            'message' => $allowed ? 'OK' : $this->policy->blockedMessage($locale),
            'data'    => [
                'allowed'     => $allowed,
                'device_type' => $this->policy->classify($ua),
                'message'     => $allowed ? null : $this->policy->blockedMessage($locale),
            ],
            'meta'    => (object) [],
        ])->header('Cache-Control', 'no-store');
    }
}
