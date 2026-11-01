<?php

namespace App\Console\Commands;

use App\Services\FileUploadService;
use App\Support\UploadMirror;
use Illuminate\Console\Command;

/**
 * بينسخ كل ملفات الرفع الموجودة حاليًا على ديسك السيرفر (storage/app/private/uploads + public/uploads) إلى S3.
 * شغّله مرة بعد نشر UploadMirror وقبل أي Deploy تاني — اللي لسه موجود على الديسك دلوقتي بيتأمّن، واللي اتمسح قبل كده مش بيرجع.
 *
 * idempotent: بيتخطى اللي موجود بنفس الحجم على S3. --dry-run بيعرض بس. --force بيرفع الكل من جديد.
 *   php artisan uploads:mirror-sync
 */
class UploadsMirrorSync extends Command
{
    protected $signature = 'uploads:mirror-sync {--dry-run : اعرض اللي هيترفع من غير ما ترفع} {--force : ارفع حتى لو الملف موجود على S3}';
    protected $description = 'Copy existing uploaded files (private + public uploads) to the durable S3 mirror.';

    public function handle(): int
    {
        if (!UploadMirror::enabled()) {
            $this->error('UploadMirror is off. Set AWS_BUCKET (or UPLOAD_MIRROR_DISK=s3) first.');
            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $disk = \Illuminate\Support\Facades\Storage::disk(UploadMirror::diskName());

        $roots = [
            FileUploadService::privateRoot() . '/uploads' => 'uploads',
            public_path('uploads') => 'uploads',
        ];

        $sent = $skipped = $failed = 0;
        $bytes = 0;
        $seen = [];

        foreach ($roots as $root => $base) {
            if (!is_dir($root)) {
                $this->line("skip (missing): {$root}");
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
                $name = $file->getFilename();
                if (str_contains($name, '.restore.') || str_ends_with($name, '.gitkeep')) {
                    continue;
                }
                $rel = $base . '/' . ltrim(substr($file->getPathname(), strlen(rtrim(str_replace('\\', '/', $root), '/'))), '/');
                if (isset($seen[$rel])) {
                    continue;
                }
                $seen[$rel] = true;
                $size = (int) $file->getSize();

                try {
                    if (!$force && $disk->exists(UploadMirror::key($rel)) && (int) $disk->size(UploadMirror::key($rel)) === $size) {
                        $skipped++;
                        continue;
                    }
                } catch (\Throwable $e) {
                    // لو التحقق فشل نحاول الرفع.
                }

                if ($dry) {
                    $this->line("[dry-run] {$rel}");
                    $sent++;
                    $bytes += $size;
                    continue;
                }
                if (UploadMirror::push($rel, $file->getPathname())) {
                    $sent++;
                    $bytes += $size;
                } else {
                    $this->warn("failed: {$rel}");
                    $failed++;
                }
            }
        }

        $this->info(sprintf('%s: %d uploaded (%.1f MB), %d already on S3, %d failed.', $dry ? 'dry-run' : 'done', $sent, $bytes / 1048576, $skipped, $failed));
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
