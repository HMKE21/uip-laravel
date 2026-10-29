<?php

namespace App\Jobs;

use App\Services\PushNotificationService;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Push للمكالمات: mode=ring (رنّة مكالمة واردة) أو mode=stop (إيقاف الرنّة على أجهزة المستلم).
 * بيتنادي بـ SendCallPushJob::send(...) → بعد الرد (dispatchAfterResponse) أو داخل الطلب لو FCM_SYNC=true.
 */
class SendCallPushJob
{
    use Dispatchable;

    public function __construct(public int $callId, public string $mode = 'ring', public string $link = '')
    {
    }

    public static function send(int $callId, string $mode = 'ring', string $link = ''): void
    {
        try {
            if (config('services.fcm.sync')) {
                static::dispatchSync($callId, $mode, $link);
            } else {
                static::dispatchAfterResponse($callId, $mode, $link);
            }
        } catch (\Throwable $e) {
            // الـ push تحسين؛ فشله ميكسرش المكالمة
        }
    }

    public function handle(PushNotificationService $push): void
    {
        if ($this->mode === 'stop') {
            $push->stopCallRing($this->callId);
        } else {
            $push->ringCall($this->callId, $this->link);
        }
    }
}
