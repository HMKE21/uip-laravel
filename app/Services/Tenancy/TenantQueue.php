<?php

namespace App\Services\Tenancy;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

/**
 * الـ queue والعملاء (الخطوة 5).
 *
 * المشكلة: لو الـ queue بالـ database driver وكان الـ default connection اتحوّل لقاعدة العميل، الجوب بيتكتب في جدول jobs بتاع العميل،
 * و`php artisan queue:work` (شغّال على قاعدة المنصة) عمره ما هيشوفه. وبعدها حتى لو اتنفّذ، هيتنفّذ من غير ما يعرف هو لأي عميل.
 *
 * الحل (يشتغل مع أي driver — database/redis/sqs):
 *  1) الجوبز كلها بتتخزّن في قاعدة/queue المنصة الأم (queue.connections.database.connection = الـ default الأصلي).
 *  2) كل payload بيشيل tenant_id (Queue::createPayloadUsing) لو الجوب اتعمله dispatch وإحنا جوه عميل.
 *  3) الـ worker قبل ما ينفّذ الجوب بيفعّل العميل (JobProcessing) وبعدها يرجّع للمنصة (JobProcessed/JobFailed/JobExceptionOccurred).
 *     لو العميل اتوقّف/اتحذف الجوب بيفشل (مش بيتنفّذ على قاعدة غلط).
 * عامل واحد (queue:work) كفاية لكل العملاء.
 */
class TenantQueue
{
    public static function register(): void
    {
        // (1) الجوبز في المنصة الأم دايمًا. بنثبّت اسم الـ connection بدل null (null = "الـ default وقت الاستخدام" = ممكن يبقى العميل).
        $central = (string) config('database.default');
        if ($central !== '' && empty(config('queue.connections.database.connection'))) {
            config(['queue.connections.database.connection' => $central]);
        }

        // (2) tenant_id في الـ payload
        \Illuminate\Queue\Queue::createPayloadUsing(static function ($connection, $queue, $payload) {
            $id = TenantContext::id();
            return $id ? ['tenant_id' => $id] : [];
        });

        // (3) تفعيل العميل حوالين الجوب
        Event::listen(JobProcessing::class, static function (JobProcessing $event): void {
            TenantContext::deactivate(); // أمان: متفضلش حالة جوب سابق
            $payload = $event->job->payload();
            $tid = isset($payload['tenant_id']) ? (int) $payload['tenant_id'] : 0;
            if ($tid <= 0) {
                return;
            }
            $tenant = TenantContext::findIsolated($tid);
            if (!$tenant) {
                // exception هنا بتخلّي الـ worker يعتبر الجوب فاشل (مش بيتنفّذ على قاعدة المنصة بالغلط).
                throw new \RuntimeException("Tenant {$tid} is not available (suspended/deleted/not ready); job not executed.");
            }
            TenantContext::activate($tenant);
        });
        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $ev) {
            Event::listen($ev, static function (): void {
                TenantContext::deactivate();
            });
        }
    }
}
