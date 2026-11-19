<?php

namespace App\Jobs;

use App\Services\Developer\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** محاولة تسليم Webhook واحدة. إعادة المحاولات بتتدار بـ backoff جوه WebhookService (وبـ dev:webhooks للي فاتت)، مش بـ retries بتاعة الـ queue. */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(private int $deliveryId)
    {
    }

    public function handle(WebhookService $svc): void
    {
        $svc->deliver($this->deliveryId);
    }
}
