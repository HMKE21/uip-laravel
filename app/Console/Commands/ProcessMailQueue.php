<?php

namespace App\Console\Commands;

use App\Services\Mail\MailboxService;
use App\Services\Mail\MailQueueService;
use Illuminate\Console\Command;

/**
 * UIPE Mail — بيسلّم الرسائل اللي وقتها جه (مجدولة / فترة Undo Send خلصت)، ويرجّع المؤجّلات (Snooze) للوارد،
 * ويعيد محاولة الإرسال الخارجي الفاشل (المرحلة 7)، ويسجّل نبضة (heartbeat) للوحة مراقبة الـ Queue.
 * مسجّل في routes/console.php كل دقيقة (محتاج cron: schedule:run).
 *   --purge  يمسح نهائيًا اللي في سلة البريد من أكتر من mailbox.trash_days (أو retention_trash_days للجامعة) (بيتشغّل يوميًا).
 */
class ProcessMailQueue extends Command
{
    protected $signature = 'mail:process {--purge}';

    protected $description = 'Delivers due scheduled / undo-send mail, wakes snoozed mail, retries failed external delivery, optionally purges old trash.';

    public function handle(MailboxService $mail, MailQueueService $queue): int
    {
        if ($this->option('purge')) {
            $this->info('purged=' . $mail->purgeOldTrash());
            return self::SUCCESS;
        }
        $r = $mail->processDue(null, 500);
        $woke = $mail->wakeSnoozed();
        $retried = $mail->retryExternal(50);
        $queue->heartbeat(['sent' => (int) $r['sent'], 'failed' => (int) $r['failed'], 'woke' => (int) $woke, 'retried' => $retried]);
        $this->info("sent={$r['sent']} failed={$r['failed']} woke={$woke} retried={$retried}");
        return self::SUCCESS;
    }
}
