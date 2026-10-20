<?php

namespace App\Console\Commands;

use App\Services\ExamAttemptService;
use Illuminate\Console\Command;

/**
 * Phase 11 backstop — نفس نمط PublishScheduledAnnouncements بالظبط.
 * ExamAttemptService::enforceTimer() بيتنادى lazily في أي request بيلمس
 * محاولة شغالة، فده كافي لمعظم الحالات، لكن طالب سايب المحاولة (قافل
 * التاب من غير submit وملمسهاش تاني خالص) محتاج sweep دوري عشان
 * المحاولة تتقفل auto_submitted بدل ما تفضل in_progress للأبد.
 *
 * سجّلها في crontab حقيقي بتاع السيرفر (كل دقيقة — التايمر بالثواني):
 *   * * * * * cd /path/to/project && php artisan exams:auto-submit-expired >> storage/logs/cron.log 2>&1
 * أو استخدم جدولة لارارفيل نفسها في routes/console.php:
 *   Schedule::command('exams:auto-submit-expired')->everyMinute();
 */
class AutoSubmitExpiredExamAttempts extends Command
{
    protected $signature = 'exams:auto-submit-expired';

    protected $description = 'Auto-submits every in-progress exam attempt whose timer has expired (backstop sweep alongside the lazy per-request timer check).';

    public function handle(ExamAttemptService $service): int
    {
        $this->info('[' . now()->format('Y-m-d H:i:s') . '] Auto-submitting expired exam attempts...');

        try {
            $count = $service->autoSubmitAllExpired();
            $this->info("Auto-submitted {$count} expired attempt(s).");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Exam attempt auto-submit sweep failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
