<?php

namespace App\Services\Developer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * نقطة واحدة تطلق منها المنصة أحداث الـ Webhooks. أي فشل هنا مبيكسرش اللي استدعاه (تسليم البريد مثلًا).
 * الـ Webhook بيستلم الحدث لو: نشط، نفس الـ mode، مشترك في النوع ده، وصندوقه (لو محدد) هو صندوق الحدث، وسياسة الجامعة بتسمح.
 */
class DevEventBus
{
    public function __construct(private WebhookService $hooks, private DeveloperAppService $apps)
    {
    }

    /**
     * @param array $data بيانات الحدث (من غير أجسام رسائل — بس معرّفات وملخّص).
     * @param ?int $appId لو الحدث خاص بتطبيق (app.authorized/revoked): بيروح لـ webhooks التطبيق ده + اللي مش مربوطة بتطبيق.
     */
    public function emit(string $event, int $userId, array $data, ?int $accountId = null, string $mode = 'live', ?int $appId = null): void
    {
        try {
            if (!config('developer.enabled') || $userId <= 0) {
                return;
            }
            $policy = $this->apps->policyFor($userId);
            if (!$policy['apps_enabled'] || !$policy['webhooks_enabled']) {
                return;
            }
            $hooks = DB::table('dev_webhooks')->where('user_id', $userId)->where('is_active', 1)->where('mode', $mode)
                ->when($accountId, fn ($q) => $q->where(fn ($w) => $w->whereNull('mail_account_id')->orWhere('mail_account_id', $accountId)))
                ->when($appId, fn ($q) => $q->where(fn ($w) => $w->whereNull('app_id')->orWhere('app_id', $appId)))
                ->get();
            foreach ($hooks as $h) {
                if (in_array($event, (array) json_decode((string) $h->events, true), true)) {
                    $this->hooks->enqueue($h, $event, $data);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Dev event emit failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
