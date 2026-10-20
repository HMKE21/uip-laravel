<?php

namespace App\Console\Commands;

use App\Services\NotificationEmailService;
use Illuminate\Console\Command;

/**
 * معالج طابور إيميلات الإشعارات (notification_email_queue): إيميلات فورية + ملخصات يومي/أسبوعي.
 * مسجّل في routes/console.php كل دقيقة، ومحتاج cron/worker بيشغّل `php artisan schedule:run` (أو schedule:work).
 *   --dry-run  يعرض كام إيميل مستحق من غير ما يبعت أو يغيّر حاجة.
 */
class SendQueuedNotificationEmails extends Command
{
    protected $signature = 'notifications:send-emails {--limit=100} {--dry-run}';

    protected $description = 'Sends the queued notification emails (project events) and digests that are due.';

    public function handle(NotificationEmailService $emails): int
    {
        $r = $emails->processDue(max(1, (int) $this->option('limit')), (bool) $this->option('dry-run'));
        $this->info(($this->option('dry-run') ? '[dry-run] ' : '') . sprintf(
            'sent=%d digests=%d retried=%d deferred=%d failed=%d expired=%d',
            $r['sent'], $r['digests'], $r['retried'], $r['deferred'], $r['failed'], $r['expired']
        ));
        return self::SUCCESS;
    }
}
