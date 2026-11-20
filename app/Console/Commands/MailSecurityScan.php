<?php

namespace App\Console\Commands;

use App\Services\Mail\MailSecurityService;
use Illuminate\Console\Command;

/**
 * UIPE Mail (المرحلة 7) — مراقبة دورية: brute-force على اللوجين (من login_events) + قفزة الـ bounces + مسح قواعد الـ IP المنتهية.
 * مسجّل في routes/console.php كل 5 دقايق.
 */
class MailSecurityScan extends Command
{
    protected $signature = 'mail:security-scan';

    protected $description = 'Detects suspicious mail/login activity and expires old IP rules.';

    public function handle(MailSecurityService $security): int
    {
        $f = $security->scan();
        $expired = $security->pruneExpiredRules();
        $this->info("bruteforce={$f['bruteforce']} bounce_spike={$f['bounce_spike']} expired_rules={$expired}");
        return self::SUCCESS;
    }
}
