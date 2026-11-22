<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantFeatureNotifier;
use App\Services\Tenancy\TenantFeatures;
use Illuminate\Console\Command;

/**
 * يبلّغ أدمن المنصة بطلبات الميزات المعلّقة اللي اتعملت قبل ما الإشعارات تشتغل.
 *   php artisan tenant:notify-pending-features
 */
class TenantNotifyPendingFeatures extends Command
{
    protected $signature = 'tenant:notify-pending-features';
    protected $description = 'Notify platform admins about feature requests that are still pending';

    public function handle(): int
    {
        $by = [];
        foreach (TenantFeatures::listRequests('pending') as $r) {
            $by[(int) $r['tenant_id']]['features'][] = $r['feature'];
            $by[(int) $r['tenant_id']]['user'] = $r['requested_by'] ?? null;
        }
        foreach ($by as $tid => $d) {
            TenantContext::runCentral(static fn () => null);
            TenantFeatureNotifier::requested($tid, $d['features'], $d['user'] ? (int) $d['user'] : null, 'pending request');
            $this->info("Tenant #{$tid}: " . implode(', ', $d['features']));
        }
        $this->info(count($by) ? 'Done.' : 'No pending requests.');
        return self::SUCCESS;
    }
}
