<?php

namespace App\Console\Commands;

use App\Services\PortalGuideSeedService;
use App\Support\PortalGuideException;
use Illuminate\Console\Command;

/**
 * يزامن المحتوى الافتراضي لصفحات الدليل (database/data/guides/*.php) مع الداتابيز.
 *
 *   php artisan guides:sync                 يضيف أي قسم/عنصر افتراضي جديد في كل البوابات (مبيلمسش تعديلات الأدمن ولا اللي مسحه)
 *   php artisan guides:sync --portal=admin  بوابة واحدة
 *   php artisan guides:sync --force         يرجّع نص المحتوى الافتراضي كله (مبيمسحش اللي الأدمن أضافه بنفسه)
 *
 * مش لازم تشغّله أول مرة — الدليل بيتزرع تلقائيًا أول ما حد يفتحه. استخدمه بعد ما تضيف محتوى افتراضي جديد في الكود.
 */
class SyncPortalGuides extends Command
{
    protected $signature = 'guides:sync {--portal= : portal slug (default: all)} {--force : overwrite default content with the shipped text}';

    protected $description = 'Sync the default portal guide content into the database';

    public function handle(PortalGuideSeedService $seeds): int
    {
        $only = $this->option('portal');
        $portals = $only ? [(string) $only] : $seeds->portals();
        $force = (bool) $this->option('force');

        if ($force && !$this->option('no-interaction')
            && !$this->confirm('--force overwrites the default guide text (content added by admins is kept). Continue?', false)) {
            return self::FAILURE;
        }

        $status = self::SUCCESS;
        foreach ($portals as $portal) {
            try {
                $r = $seeds->seedPortal($portal, $force);
                $this->line(sprintf(
                    '%-15s %s  +%d sections  +%d items  ~%d updated',
                    $portal,
                    $r['created'] ? 'created' : 'synced ',
                    $r['added_sections'],
                    $r['added_items'],
                    $r['updated']
                ));
            } catch (PortalGuideException $e) {
                $this->error("$portal: " . $e->getMessage());
                $status = self::FAILURE;
            }
        }
        return $status;
    }
}
