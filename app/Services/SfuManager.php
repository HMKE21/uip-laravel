<?php

namespace App\Services;

use App\Contracts\SfuProvider;

/**
 * بيحدد مين المزوّد الفعّال دلوقتي (Cloudflare ولا mediasoup بتاعنا) حسب إعداد اللوحة `sfu_provider`.
 * دلوقتي الاختيار ثابت من الإعدادات؛ الـ failover التلقائي (health check) بيتضاف هنا في الخطوة 6.
 */
class SfuManager
{
    public function __construct(
        private CloudflareSfuService $cloudflare,
        private SelfHostedSfuService $selfHosted
    ) {
    }

    /** @return 'cloudflare'|'selfhosted' */
    public function provider(): string
    {
        return MeetingAdminSettings::sfu()['provider'] === 'selfhosted' ? 'selfhosted' : 'cloudflare';
    }

    public function active(): SfuProvider
    {
        return $this->provider() === 'selfhosted' ? $this->selfHosted : $this->cloudflare;
    }

    public function enabled(): bool
    {
        return $this->active()->enabled();
    }

    public function cloudflare(): CloudflareSfuService
    {
        return $this->cloudflare;
    }

    public function selfHosted(): SelfHostedSfuService
    {
        return $this->selfHosted;
    }
}
