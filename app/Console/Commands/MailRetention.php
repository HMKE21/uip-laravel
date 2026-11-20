<?php

namespace App\Console\Commands;

use App\Services\Mail\MailRetentionService;
use Illuminate\Console\Command;

/**
 * UIPE Mail (المرحلة 7) — سياسات الاحتفاظ لكل جامعة (spam / sent / كل حاجة) + تنضيف سجلات التشغيل القديمة.
 *   php artisan mail:retention --dry    # عدّ بس من غير مسح
 * الصناديق تحت Legal hold بتتخطّى. مسجّل يوميًا في routes/console.php.
 */
class MailRetention extends Command
{
    protected $signature = 'mail:retention {--dry}';

    protected $description = 'Applies per-university mail retention policies (spam/sent/all) and prunes old operational logs.';

    public function handle(MailRetentionService $retention): int
    {
        $dry = (bool) $this->option('dry');
        $r = $retention->run($dry);
        $this->info(($dry ? '[dry] would delete' : 'deleted') . " spam={$r['spam']} sent={$r['sent']} all={$r['all']}");
        return self::SUCCESS;
    }
}
