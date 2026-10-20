<?php

namespace App\Console\Commands;

use App\Services\ExamReminderService;
use Illuminate\Console\Command;

/**
 * تذكيرات الامتحانات (قبل الفتح / قبل الإقفال، 24 ساعة ثم ساعة). المنطق كله في ExamReminderService.
 * مسجّلة في routes/console.php عبر Schedule كل 5 دقايق — محتاجة على السيرفر cron واحد بس:
 *   * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
 * (لو كنت مسجّل exams:notify-upcoming في crontab قبل كده، سيبه أو شيله — الـ dedup بيمنع أي تكرار).
 */
class NotifyUpcomingExamEvents extends Command
{
    protected $signature = 'exams:notify-upcoming';

    protected $description = 'Sends automatic reminders to eligible students before an exam opens and before it closes (24h and 1h).';

    public function handle(ExamReminderService $reminders): int
    {
        $r = $reminders->sweep();
        $this->info("Opening reminders sent: {$r['opens']}. Closing reminders sent: {$r['closes']}.");
        return self::SUCCESS;
    }
}
