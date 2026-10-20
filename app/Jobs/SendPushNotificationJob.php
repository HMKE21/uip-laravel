<?php

namespace App\Jobs;

use App\Services\PushNotificationService;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * بيبعت Push لإشعار اتسجّل. بيتنادي بـ SendPushNotificationJob::dispatchAfterResponse($id) فبيشتغل بعد
 * ما الرد يتبعت للمستخدم (مفيش تأخير في الطلب الأصلي، ومش محتاج queue worker شغّال).
 */
class SendPushNotificationJob
{
    use Dispatchable;

    public function __construct(public int $notificationId)
    {
    }

    public function handle(PushNotificationService $push): void
    {
        $push->sendForNotification($this->notificationId);
    }
}
