<?php

namespace App\Console\Commands;

use App\Services\Identity\LoginHistoryService;
use App\Services\Mail\MailMembershipService;
use App\Services\Mail\MailProvisioningService;
use Illuminate\Console\Command;

/**
 * UIPE Mail — المرحلة 5: المزامنة المجدولة للهوية.
 *   (بدون خيارات) = --provision --membership --prune
 *   --provision  ينشئ الصناديق الناقصة لكل الجامعات اللي auto_provision فيها شغّال (آمن للتكرار).
 *   --membership يعلّق صناديق الأعضاء اللي عضويتهم انتهت (تخرّج/إيقاف/انتهاء تاريخ) ويستأنف اللي رجعت عضويتهم.
 *   --prune      يمسح سجل الدخول الأقدم من LOGIN_EVENTS_DAYS (180 يوم افتراضيًا).
 */
class MailIdentitySync extends Command
{
    protected $signature = 'mail:identity-sync {--provision} {--membership} {--prune}';
    protected $description = 'UIPE Mail identity: auto-provision missing mailboxes and prune old login events';

    public function handle(MailProvisioningService $prov, MailMembershipService $membership, LoginHistoryService $history): int
    {
        $all = !$this->option('provision') && !$this->option('membership') && !$this->option('prune');
        if ($all || $this->option('provision')) {
            $r = $prov->syncAll();
            $this->info("Provision: tenants={$r['tenants']} created={$r['created']} failed={$r['failed']} remaining={$r['remaining']}");
        }
        if ($all || $this->option('membership')) {
            $m = $membership->sweep();
            $this->info("Membership: suspended={$m['suspended']} resumed={$m['resumed']}");
        }
        if ($all || $this->option('prune')) {
            $this->info('Pruned login events: ' . $history->prune());
        }
        return self::SUCCESS;
    }
}
