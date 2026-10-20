<?php

namespace App\Console\Commands;

use App\Support\SvgGuard;
use Illuminate\Console\Command;

/**
 * php artisan ops:svg-audit              → يفحص كل .svg مرفوع تحت public/uploads بنفس قواعد الرفع ويطبع المشبوه.
 * php artisan ops:svg-audit --quarantine → يغيّر امتداد المشبوه لـ .svg.unsafe (بيتخدم كتحميل/404 مش كـ SVG).
 * بيرجّع exit code 1 لو لقى ملفات مشبوهة (مناسب لـ CI/نشر). الصفوف في الداتابيز اللي بتشاور على ملف اتعزل هتدي 404.
 */
class SvgAudit extends Command
{
    protected $signature = 'ops:svg-audit {--quarantine : Rename unsafe SVGs to *.svg.unsafe}';

    protected $description = 'Scans already-uploaded SVG files for active content (scripts, event handlers, external links).';

    public function handle(): int
    {
        $root = public_path('uploads');
        if (!is_dir($root)) {
            $this->info('No uploads directory — nothing to scan.');
            return self::SUCCESS;
        }

        $scanned = 0;
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'svg') {
                continue;
            }
            $scanned++;
            try {
                SvgGuard::assertSafe((string) file_get_contents($file->getPathname()));
            } catch (\RuntimeException $e) {
                $bad[] = [$file->getPathname(), $e->getMessage()];
            }
        }

        $this->info("Scanned {$scanned} SVG file(s).");
        foreach ($bad as [$path, $why]) {
            $rel = ltrim(str_replace(public_path(), '', $path), '/');
            $this->warn("UNSAFE  {$rel}  — {$why}");
            if ($this->option('quarantine') && @rename($path, $path . '.unsafe')) {
                $this->line("        quarantined → {$rel}.unsafe");
            }
        }
        if (!$bad) {
            $this->info('All SVG files are clean.');
            return self::SUCCESS;
        }
        return self::FAILURE;
    }
}
