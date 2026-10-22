<?php

namespace App\Console\Commands;

use App\Services\DriveContentSearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** يفهرس نصوص كل ملفات "جهازي" مرة واحدة (البحث في المحتوى بيكمّل الفهرسة لوحده، ده بس عشان تسبق الطلاب). */
class DriveIndexContent extends Command
{
    protected $signature = 'drive:index-content {--user= : user id واحد بس}';
    protected $description = 'Index the text of My PC files for search-inside-files';

    public function handle(DriveContentSearchService $svc): int
    {
        $uids = $this->option('user')
            ? [(int) $this->option('user')]
            : DB::table('drive_nodes')->where('type', 'file')->distinct()->pluck('user_id')->map(fn ($i) => (int) $i)->all();
        $total = 0;
        foreach ($uids as $uid) {
            while (($n = $svc->catchUp($uid, 200, 64_000_000)) > 0) {
                $total += $n;
            }
        }
        $this->info("Indexed {$total} file(s) for " . count($uids) . ' user(s).');

        return self::SUCCESS;
    }
}
