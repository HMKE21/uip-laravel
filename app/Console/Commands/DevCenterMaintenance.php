<?php

namespace App\Console\Commands;

use App\Services\Developer\DevLogService;
use App\Services\Developer\DeveloperAppService;
use App\Services\Developer\WebhookService;
use Illuminate\Console\Command;

/** Developer Center: إعادة محاولات الـ Webhooks المستحقة + تسليم العالق، وتنضيف السجلات/الأكواد/التوكنز القديمة. */
class DevCenterMaintenance extends Command
{
    protected $signature = 'dev:maintenance {--webhooks : retry due webhook deliveries only} {--prune : prune old logs/tokens only}';
    protected $description = 'Developer Center: retry due webhook deliveries and prune old logs, codes and tokens';

    public function handle(WebhookService $hooks, DevLogService $logs, DeveloperAppService $apps): int
    {
        $both = !$this->option('webhooks') && !$this->option('prune');
        if ($both || $this->option('webhooks')) {
            $this->info('Webhook retries: ' . $hooks->retryDue() . ', stuck swept: ' . $hooks->sweepStuck());
        }
        if ($both || $this->option('prune')) {
            $r = $logs->prune();
            $this->info('Pruned: ' . json_encode($r));
            $this->info('Rotated keys revoked after grace: ' . $apps->revokeRotatedExpired());
        }
        return self::SUCCESS;
    }
}
