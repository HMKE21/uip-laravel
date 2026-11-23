<?php

namespace App\Console\Commands;

use App\Services\CloudflareSfuService;
use App\Services\SelfHostedSfuService;
use App\Services\SfuHealth;
use Illuminate\Console\Command;

/**
 * فحص صحة مزوّدي الـ SFU (Failover — الخطوة 6). بيتشغّل من الـ scheduler كل دقيقة، وبيعيد الفحص جواه كل
 * SFU_PROBE_INTERVAL ثانية (الافتراضي 10) لحد قرب نهاية الدقيقة — لأن cron مابينزلش تحت دقيقة.
 *
 *   php artisan sfu:health          # الدورة الكاملة (للـ scheduler)
 *   php artisan sfu:health --once   # فحص واحد وبيطبع النتيجة (للتجربة اليدوية)
 *
 * mediasoup: طلب /health خفيف كل مرة. Cloudflare: بيفتح session حقيقية فبيتفحص أقل (SFU_CF_PROBE_INTERVAL، 0 = مايتفحصش).
 * بيفحص بس المزوّد المفعّل ببياناته الكاملة. نتيجة الفحص بتتسجّل في SfuHealth (نفس الدايرة اللي بتتأثر بالطلبات الفعلية).
 */
class SfuHealthCheck extends Command
{
    protected $signature = 'sfu:health {--once : فحص واحد وبيطبع النتيجة}';

    protected $description = 'Probe the SFU providers (mediasoup / Cloudflare) and feed the failover circuit breaker';

    public function handle(SelfHostedSfuService $own, CloudflareSfuService $cf): int
    {
        $interval = max(2, (int) config('webrtc.failover.probe_interval', 10));
        $cfEvery = max(0, (int) config('webrtc.failover.cloudflare_probe_interval', 120));
        $once = (bool) $this->option('once');
        $deadline = time() + 52; // نخلّص قبل الدقيقة الجاية عشان withoutOverlapping مايفوّتش دورة

        do {
            $this->probeOwn($own, $once);
            $this->probeCloudflare($cf, $cfEvery, $once);
            if ($once) {
                break;
            }
            sleep($interval);
        } while (time() + 1 < $deadline);

        return self::SUCCESS;
    }

    private function probeOwn(SelfHostedSfuService $own, bool $verbose): void
    {
        if (!$own->enabled()) {
            return;
        }
        $r = $own->probe();
        if ($verbose) {
            $this->line('selfhosted: ' . ($r['ok'] ? 'OK ' . json_encode($r['data']) : 'FAIL status=' . $r['status'] . ' ' . json_encode($r['data'])));
        }
    }

    private function probeCloudflare(CloudflareSfuService $cf, int $every, bool $verbose): void
    {
        if ($every <= 0 || !$cf->enabled()) {
            return;
        }
        $last = (int) SfuHealth::state('cloudflare')['probed_at'];
        if (!$verbose && (time() - $last) < $every) {
            return;
        }
        $r = $cf->probe();
        if ($verbose) {
            $this->line('cloudflare: ' . ($r['ok'] ? 'OK' : 'FAIL status=' . $r['status'] . ' ' . json_encode($r['data'])));
        }
    }
}
