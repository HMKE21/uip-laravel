<?php

namespace App\Console\Commands;

use App\Services\FileUploadService;
use Illuminate\Console\Command;

/**
 * بينقل ملفات الشات القديمة من public/uploads/messages (اللي كان أي حد معاه الرابط يفتحه) إلى التخزين الخاص
 * storage/app/private/uploads/messages، اللي مفيش ليه رابط مباشر ولا بيتسلّم غير من خلال route العضوية.
 *
 * - الـ stored_path / thumbnail_path في الداتابيز مابيتغيروش (نسبيين بنفس الشكل) — FileUploadService::absolutePath
 *   بيلاقي الملف في المكان الجديد.
 * - idempotent: بيتشغّل أكتر من مرة بأمان، وبيتخطى اللي اتنقل. --dry-run بيعرض بس.
 * - شغّله مرة بعد كل نشر لحد ما يرجّع 0 ملف، وبعد النقل مفيش حاجة تحت public/uploads/messages.
 */
class MessagesPrivatizeAttachments extends Command
{
    protected $signature = 'messages:privatize-attachments {--dry-run : اعرض اللي هيتنقل من غير ما تنقل}';
    protected $description = 'Move legacy public chat attachments (public/uploads/messages) into private storage.';

    public function handle(): int
    {
        $base = trim((string) config('upload.paths.messages', 'uploads/messages'), '/');
        $source = public_path($base);
        $dry = (bool) $this->option('dry-run');

        if (!is_dir($source)) {
            $this->info("Nothing to move: {$source} does not exist.");
            return self::SUCCESS;
        }
        if (!FileUploadService::isPrivatePath($base)) {
            $this->error("The 'messages' category is not private (config upload.private_categories). Aborting.");
            return self::FAILURE;
        }

        $moved = 0;
        $failed = 0;
        $bytes = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $rel = $base . '/' . ltrim(substr($file->getPathname(), strlen(rtrim(str_replace('\\', '/', $source), '/'))), '/');
            $target = FileUploadService::privateRoot() . '/' . $rel;
            $size = (int) $file->getSize();

            if ($dry) {
                $this->line("[dry-run] {$rel}");
                $moved++;
                $bytes += $size;
                continue;
            }

            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                $this->warn("Cannot create {$dir}");
                $failed++;
                continue;
            }
            // rename ذرّي لو نفس الـ filesystem؛ غير كده copy ثم تحقق من الحجم ثم مسح الأصل.
            if (@rename($file->getPathname(), $target)) {
                $moved++;
                $bytes += $size;
                continue;
            }
            if (@copy($file->getPathname(), $target) && @filesize($target) === $size) {
                @unlink($file->getPathname());
                $moved++;
                $bytes += $size;
            } else {
                @unlink($target);
                $this->warn("Failed to move {$rel}");
                $failed++;
            }
        }

        $this->info(($dry ? 'Would move ' : 'Moved ') . "{$moved} file(s) (" . round($bytes / 1048576, 1) . " MB); {$failed} failed.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
