<?php

namespace App\Console\Commands;

use App\Services\LexiconSyncService;
use App\Support\LexiconStore;
use Illuminate\Console\Command;

/**
 * يسحب قوايم الـ Lexicon Hub (دومينات + كلمات/عبارات كهاشات) ويتحقق من التوقيع وهاش كل ملف ويحفظها محليًا.
 * يرفض أي تحديث بيصغّر قايمة الدومينات لأقل من 50%، ولو كل المصادر وقعت بيكمّل بآخر نسخة محفوظة.
 * الجدولة: routes/console.php (يوميًا 03:20) أو crontab.
 */
class LexiconSync extends Command
{
    protected $signature = 'lexicon:sync {--force : نزّل كل الملفات من جديد حتى لو الإصدار نفسه} {--status : اعرض النسخة المثبّتة بس}';
    protected $description = 'Sync the Lexicon Hub block lists (hashed domains + terms) into local storage';

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->showStatus();
        }

        $r = (new LexiconSyncService())->sync((bool) $this->option('force'));
        foreach ($r['errors'] ?? [] as $e) {
            $this->warn('  ✗ ' . $e);
        }
        switch ($r['status']) {
            case 'updated':
            case 'current':
                $c = $r['counts'] ?? [];
                $this->info($r['message'] . ' source=' . ($r['source'] ?? '-') . ' domains=' . ($c['d'] ?? 0) . ' terms=' . ($c['w'] ?? 0) . ' custom=' . ($c['c'] ?? 0));

                return self::SUCCESS;
            case 'not_configured':
                $this->line($r['message'] . ' Nothing to do.');

                return self::SUCCESS;
            case 'busy':
                $this->line($r['message']);

                return self::SUCCESS;
            default:
                $this->error($r['message']);

                return self::FAILURE;
        }
    }

    private function showStatus(): int
    {
        $s = LexiconStore::status();
        if (!$s['loaded']) {
            $this->warn('No lexicon installed (built-in dictionary only). Last error: ' . ($s['last_error'] ?? '-'));

            return self::SUCCESS;
        }
        $c = $s['counts'] ?? [];
        $this->line('version=' . $s['version'] . ' source=' . $s['source'] . ' synced_at=' . $s['synced_at'] . ($s['stale'] ? '  (STALE > 3 days)' : ''));
        $this->line('domains=' . ($c['d'] ?? 0) . ' terms=' . ($c['w'] ?? 0) . ' custom=' . ($c['c'] ?? 0));
        $this->line('last_attempt=' . ($s['last_attempt_at'] ?? '-') . ' last_success=' . ($s['last_success_at'] ?? '-') . ' last_error=' . ($s['last_error'] ?? '-'));

        return self::SUCCESS;
    }
}
