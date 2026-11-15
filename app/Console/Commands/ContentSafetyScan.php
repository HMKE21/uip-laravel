<?php

namespace App\Console\Commands;

use App\Services\ContentSafetyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * يفحص المحتوى المتخزّن قبل تفعيل الفلتر (رسائل / منشورات / تعليقات / أسماء المستخدمين) ويطبع اللي مخالف.
 *
 *   php artisan content-safety:scan                 تقرير بس (من غير أي تعديل)
 *   php artisan content-safety:scan --days=30       آخر 30 يوم بس
 *   php artisan content-safety:scan --csv=storage/content-safety-report.csv
 *
 * مقصود إنه تقرير مش حذف تلقائي: الأدمن يراجع ويحذف من لوحة المنشورات / مراقبة الرسائل.
 */
class ContentSafetyScan extends Command
{
    protected $signature = 'content-safety:scan {--days=0 : آخر N يوم (0 = الكل)} {--csv= : مسار ملف CSV للتقرير} {--limit=200000 : أقصى عدد صفوف لكل جدول}';
    protected $description = 'Report stored messages / posts / comments / names that violate the content-safety policy (report only).';

    public function handle(ContentSafetyService $safety): int
    {
        $days = max(0, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $targets = [
            ['messages', 'id', ['body'], 'created_at', false],
            ['posts', 'id', ['title', 'body'], 'created_at', false],
            ['post_comments', 'id', ['body'], 'created_at', false],
            ['users', 'id', ['full_name'], 'created_at', true],
        ];
        $rows = [];
        foreach ($targets as [$table, $pk, $cols, $dateCol, $strict]) {
            if (!Schema::hasTable($table)) {
                $this->warn("skip {$table} (no table)");
                continue;
            }
            $cols = array_values(array_filter($cols, fn ($c) => Schema::hasColumn($table, $c)));
            if (!$cols) {
                continue;
            }
            $q = DB::table($table)->select(array_merge([$pk], $cols));
            if (Schema::hasColumn($table, 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            if ($days > 0 && Schema::hasColumn($table, $dateCol)) {
                $q->where($dateCol, '>=', now()->subDays($days));
            }
            $found = 0;
            $q->orderBy($pk)->limit($limit)->get()->each(function ($r) use ($safety, $table, $pk, $cols, $strict, &$rows, &$found) {
                foreach ($cols as $c) {
                    $hit = $safety->checkText((string) ($r->$c ?? ''), $strict);
                    if ($hit) {
                        $rows[] = [$table, $r->$pk, $c, $hit['category'], $hit['term']];
                        $found++;
                        break;
                    }
                }
            });
            $this->line(sprintf('%-14s flagged: %d', $table, $found));
        }
        if ($rows) {
            $this->table(['table', 'id', 'column', 'category', 'term'], array_slice($rows, 0, 100));
            if (count($rows) > 100) {
                $this->line('… ' . (count($rows) - 100) . ' more (use --csv to export all)');
            }
        } else {
            $this->info('Nothing flagged.');
        }
        if ($path = (string) $this->option('csv')) {
            $fh = fopen($path, 'w');
            if ($fh) {
                fputcsv($fh, ['table', 'id', 'column', 'category', 'term']);
                foreach ($rows as $r) {
                    fputcsv($fh, $r);
                }
                fclose($fh);
                $this->info("CSV written: {$path}");
            }
        }

        return self::SUCCESS;
    }
}
