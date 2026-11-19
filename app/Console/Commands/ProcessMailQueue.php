<?php

namespace App\Console\Commands;

use App\Services\Mail\MailboxService;
use Illuminate\Console\Command;

/**
 * UIPE Mail — بيسلّم الرسائل اللي وقتها جه (مجدولة / فترة Undo Send خلصت)، ويرجّع المؤجّلات (Snooze) للوارد.
 * مسجّل في routes/console.php كل دقيقة (محتاج cron: schedule:run).
 *   --purge  يمسح نهائيًا اللي في سلة البريد من أكتر من mailbox.trash_days (بيتشغّل يوميًا).
 */
class ProcessMailQueue extends Command
{
    protected $signature = 'mail:process {--purge}';

    protected $description = 'Delivers due scheduled / undo-send mail, wakes snoozed mail, optionally purges old trash.';

    public function handle(MailboxService $mail): int
    {
        if ($this->option('purge')) {
            $this->info('purged=' . $mail->purgeOldTrash());
            return self::SUCCESS;
        }
        $r = $mail->processDue(null, 500);
        $woke = $mail->wakeSnoozed();
        $this->info("sent={$r['sent']} failed={$r['failed']} woke={$woke}");
        return self::SUCCESS;
    }
}
