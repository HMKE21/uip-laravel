<?php

namespace App\Console\Commands;

use App\Services\ProjectDeadlineReminderService;
use Illuminate\Console\Command;

/** تذكير قرب موعد تسليم مشروع التخرج (7/3/1 يوم). مسجّل في routes/console.php كل ساعة؛ الـ dedup بيمنع التكرار. */
class SendProjectDeadlineReminders extends Command
{
    protected $signature = 'projects:send-deadline-reminders';

    protected $description = 'Reminds students who have not submitted their graduation project as the submission deadline approaches.';

    public function handle(ProjectDeadlineReminderService $reminders): int
    {
        $r = $reminders->sweep();
        $this->info("Deadlines in a reminder window: {$r['deadlines']}. Reminders sent: {$r['notified']}.");
        return self::SUCCESS;
    }
}
