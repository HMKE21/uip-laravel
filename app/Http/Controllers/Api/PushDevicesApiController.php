<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PushNotificationService;
use Illuminate\Http\Request;

/**
 * POST   /api/v1/push/devices   { token, platform?, locale?, app_version? }  تسجيل/تجديد توكن الجهاز بعد تسجيل الدخول
 * DELETE /api/v1/push/devices   { token }                                    عند تسجيل الخروج
 * أي يوزر مسجّل دخول (uip.auth). الـ X-Device-Id بيتاخد من الهيدر اللي التطبيق بيبعته.
 */
class PushDevicesApiController extends Controller
{
    public function __construct(private PushNotificationService $push)
    {
    }

    public function register(Request $request)
    {
        $token = trim((string) $request->input('token', ''));
        if ($token === '' || strlen($token) > 512 || !preg_match('/^[A-Za-z0-9_:\-\.]+$/', $token)) {
            return $this->apiError('Invalid device token.', ['token' => ['A valid token is required.']], 422);
        }
        $platform = $request->input('platform') === 'ios' ? 'ios' : 'android';
        $deviceId = trim((string) $request->header('X-Device-Id', ''));

        $this->push->registerDevice(
            (int) $request->attributes->get('uip_user_id'),
            $token,
            $deviceId !== '' ? mb_substr($deviceId, 0, 64) : null,
            $platform,
            (string) $request->input('locale', $request->header('X-Locale', '')),
            (string) $request->input('app_version', ''),
        );

        return $this->apiSuccess(['registered' => true], 'Device registered.');
    }

    public function unregister(Request $request)
    {
        $token = trim((string) $request->input('token', ''));
        if ($token !== '') {
            $this->push->unregisterDevice((int) $request->attributes->get('uip_user_id'), $token);
        }
        return $this->apiSuccess(['registered' => false], 'Device unregistered.');
    }
}
