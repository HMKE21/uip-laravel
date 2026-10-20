<?php

namespace App\Console\Commands;

use App\Services\AnnouncementService;
use Illuminate\Console\Command;

/**
 * منقولة من cron/publish_scheduled_announcements.php القديمة — بدل ما
 * تفضل standalone PHP script بتعمل bootstrap يدوي لـ Core\Autoloader/
 * .env زي القديمة، بقت Artisan command عادي عشان تستخدم container
 * لارافيل نفسه. المنطق الجوهري متغيّرش خالص:
 * AnnouncementService::notifyDuePending() بتلاقي كل إعلان publish_at
 * بتاعه استحق ولسه ما notified_at اتحطلوش قيمة، تبعت الإشعارات لكل
 * طالب داخل نطاق الاستهداف، وتعلّمه notified (نفس الميثود بالظبط اللي
 * AnnouncementService::publish() بتنادي لو الإعلان فوري).
 *
 * سجّلها في crontab حقيقي بتاع السيرفر (نفس تردد القديم — كل 5 دقايق):
 *   * / 5 * * * * cd /path/to/project && php artisan announcements:publish-due >> storage/logs/cron.log 2>&1
 * (احذف المسافة بين * و / فوق — اتحطت بس عشان الكومنت متتفسرش هي نفسها
 * كـ cron syntax). أو استخدم جدولة لارافيل نفسها في routes/console.php:
 *   Schedule::command('announcements:publish-due')->everyFiveMinutes();
 */
class PublishScheduledAnnouncements extends Command
{
    protected $signature = 'announcements:publish-due';

    protected $description = 'Notifies students for every scheduled announcement whose publish_at has now arrived (mirrors the old cron/publish_scheduled_announcements.php sweep).';

    public function handle(AnnouncementService $service): int
    {
        $this->info('[' . now()->format('Y-m-d H:i:s') . '] Publishing due scheduled announcements...');

        try {
            $count = $service->notifyDuePending();
            $this->info("Notified students for {$count} newly-due announcement(s).");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Scheduled announcement publish sweep failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
