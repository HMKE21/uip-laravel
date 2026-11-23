<?php

namespace App\Services;

use App\Contracts\SfuProvider;

/**
 * بيحدد مين المزوّد (Cloudflare ولا mediasoup بتاعنا) — الإعداد `sfu_provider` هو "المفضّل"، والـ failover (الخطوة 6)
 * بيحوّل لو المفضّل واقع (SfuHealth) والتاني شغّال.
 *
 * القرار على مستوى *الغرفة* مش عالمي: كل اجتماع بيتثبّت على مزوّد واحد طول ما هو شغّال (MeetingTransportService::providerFor)،
 * عشان الكل يبعت ويستقبل من نفس المكان. الاجتماعات الجديدة بتاخد المفضّل لو رجع سليم (failback من غير ما نقطع اجتماع شغّال).
 * choose() دالة نقية (من غير cache/إعدادات) عشان تتختبر لوحدها.
 */
class SfuManager
{
    public function __construct(
        private CloudflareSfuService $cloudflare,
        private SelfHostedSfuService $selfHosted
    ) {
    }

    /** المزوّد المفضّل من اللوحة. @return 'cloudflare'|'selfhosted' */
    public function preferred(): string
    {
        return MeetingAdminSettings::sfu()['provider'] === 'selfhosted' ? 'selfhosted' : 'cloudflare';
    }

    public static function other(string $provider): string
    {
        return $provider === 'selfhosted' ? 'cloudflare' : 'selfhosted';
    }

    public function failoverEnabled(): bool
    {
        return (bool) MeetingAdminSettings::sfu()['failover'];
    }

    public function service(string $provider): SfuProvider
    {
        return $provider === 'selfhosted' ? $this->selfHosted : $this->cloudflare;
    }

    /** مفعّل من اللوحة + بياناته كاملة + الدايرة بتاعته مش مفتوحة (مش واقع). */
    public function usable(string $provider): bool
    {
        return $this->service($provider)->enabled() && !SfuHealth::isDown($provider);
    }

    /**
     * قرار نقي.
     * - failover مقفول: اللي الغرفة عليه فضل عليه (حتى لو الأدمن غيّر المفضّل أثناء الاجتماع)، والغرفة الجديدة تاخد المفضّل.
     * - failover شغّال: الغرفة بتفضل على مزوّدها طول ما هو سليم؛ لو وقع → المفضّل لو سليم → التاني لو سليم → سيبها.
     *
     * @param array<string,bool> $usable provider => سليم؟
     */
    public static function choose(string $preferred, ?string $current, array $usable, bool $failover): string
    {
        $preferred = $preferred === 'selfhosted' ? 'selfhosted' : 'cloudflare';
        if (!in_array($current, SfuHealth::PROVIDERS, true)) {
            $current = null;
        }
        if (!$failover) {
            return $current ?? $preferred;
        }
        if ($current !== null && ($usable[$current] ?? false)) {
            return $current;
        }
        if ($usable[$preferred] ?? false) {
            return $preferred;
        }
        $other = self::other($preferred);
        if ($usable[$other] ?? false) {
            return $other;
        }

        return $current ?? $preferred;
    }

    /**
     * @param string|null $current  المزوّد اللي الغرفة عليه دلوقتي (null = غرفة جديدة)
     * @param string[]    $exclude  مزوّدين نتجنبهم (فشلوا عند عملاء الغرفة دي)
     */
    public function pick(?string $current = null, array $exclude = []): string
    {
        $usable = [];
        foreach (SfuHealth::PROVIDERS as $p) {
            $usable[$p] = !in_array($p, $exclude, true) && $this->usable($p);
        }

        return self::choose($this->preferred(), $current, $usable, $this->failoverEnabled());
    }

    // ---- توافق مع الخطوة 5: القرار العام (لغرفة جديدة)

    /** @return 'cloudflare'|'selfhosted' */
    public function provider(): string
    {
        return $this->pick();
    }

    public function active(): SfuProvider
    {
        return $this->service($this->provider());
    }

    public function enabled(): bool
    {
        return $this->usable($this->provider());
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
