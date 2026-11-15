<?php

namespace App\Services\Ai;

/**
 * المزوّد رجّع HTTP غير 2xx. بنحتفظ بالـ status وبرسالة المزوّد الأصلية عشان
 * الواجهة/اللوج يوضّحوا السبب الحقيقي (402 = رصيد/اشتراك/كوتا، 401 = مفتاح غلط...).
 */
class AiProviderException extends \RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message, public readonly string $providerMessage = '')
    {
        parent::__construct($message, $httpStatus);
    }

    /** أخطاء بيفيد معاها نجرب موديل/مزوّد احتياطي (رصيد/كوتا/ضغط/سيرفر واقع/موديل مش موجود). */
    public function isFallbackWorthy(): bool
    {
        return in_array($this->httpStatus, [402, 404, 408, 429], true) || $this->httpStatus >= 500;
    }
}
