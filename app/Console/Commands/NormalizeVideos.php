<?php

namespace App\Console\Commands;

use App\Services\FileUploadService;
use App\Services\MediaProbeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * يحوّل الفيديوهات القديمة (ستوري / بوستات / رسائل) لـ mp4 (H.264 + AAC) عشان تشتغل في كل المتصفحات.
 * الفيديوهات الجديدة بتتحوّل لوحدها وقت الرفع؛ الأمر ده للي اترفع قبل كده ومش بيشتغل.
 *
 *   php artisan videos:normalize --dry-run          (يعرض بس)
 *   php artisan videos:normalize                    (الكل)
 *   php artisan videos:normalize --only=stories --limit=20
 *
 * آمن للتكرار: الفيديو اللي بقى mp4/H.264 بيتعمله remux سريع بس. لو ffmpeg فشل على ملف، الأصلي بيفضل زي ما هو.
 */
class NormalizeVideos extends Command
{
    protected $signature = 'videos:normalize
        {--only= : stories | posts | messages (الافتراضي: الكل)}
        {--limit=0 : أقصى عدد فيديوهات في التشغيلة (0 = من غير حد)}
        {--dry-run : يعرض اللي هيتحوّل من غير ما يغيّر حاجة}';

    protected $description = 'Convert existing uploaded videos (stories, posts, chat) to web-playable MP4 (H.264/AAC).';

    public function handle(MediaProbeService $probe): int
    {
        if (!$probe->available()) {
            $this->error('ffmpeg / ffprobe are not available on this server.');

            return self::FAILURE;
        }

        $only = trim((string) $this->option('only'));
        $limit = max(0, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry-run');
        $left = $limit > 0 ? $limit : PHP_INT_MAX;
        $total = ['ok' => 0, 'skip' => 0, 'fail' => 0];

        if (($only === '' || $only === 'stories') && Schema::hasTable('stories')) {
            $this->line('— stories');
            DB::table('stories')->where('type', 'video')->whereNotNull('media_path')->orderBy('id')
                ->chunkById(50, function ($rows) use ($probe, $dry, &$left, &$total) {
                    foreach ($rows as $r) {
                        if ($left <= 0) {
                            return false;
                        }
                        $this->convert($probe, $dry, $r->media_path, 'story #' . $r->id, function ($conv) use ($r) {
                            DB::table('stories')->where('id', $r->id)->update(['media_path' => $conv['stored_path']]);
                        }, $left, $total);
                    }
                });
        }

        foreach (['posts' => 'post_media', 'messages' => 'message_attachments'] as $name => $table) {
            if (($only !== '' && $only !== $name) || !Schema::hasTable($table)) {
                continue;
            }
            $this->line('— ' . $name);
            DB::table($table)->where('kind', 'video')->whereNotNull('stored_path')->orderBy('id')
                ->chunkById(50, function ($rows) use ($probe, $dry, $table, &$left, &$total) {
                    foreach ($rows as $r) {
                        if ($left <= 0) {
                            return false;
                        }
                        $this->convert($probe, $dry, $r->stored_path, $table . ' #' . $r->id, function ($conv) use ($r, $table) {
                            $base = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', (string) ($r->original_name ?? ''));
                            DB::table($table)->where('id', $r->id)->update([
                                'stored_path'   => $conv['stored_path'],
                                'extension'     => $conv['extension'],
                                'mime_type'     => $conv['mime_type'],
                                'size_bytes'    => $conv['size_bytes'],
                                'original_name' => ($base !== '' ? $base : 'video') . '.mp4',
                            ]);
                        }, $left, $total);
                    }
                });
        }

        $this->info("Done. converted={$total['ok']} skipped={$total['skip']} failed={$total['fail']}" . ($dry ? ' (dry run)' : ''));

        return self::SUCCESS;
    }

    private function convert(MediaProbeService $probe, bool $dry, string $path, string $label, \Closure $save, int &$left, array &$total): void
    {
        if (!is_file(FileUploadService::absolutePath($path))) {
            $this->warn("  {$label}: file missing, skipped");
            $total['skip']++;

            return;
        }
        if ($dry) {
            $this->line("  {$label}: {$path}");
            $total['skip']++;

            return;
        }
        $left--;
        $conv = $probe->makeWebPlayable($path);
        if (!$conv) {
            $this->warn("  {$label}: not converted (not a video or ffmpeg failed)");
            $total['fail']++;

            return;
        }
        $save($conv);
        $this->line("  {$label}: -> {$conv['stored_path']}");
        $total['ok']++;
    }
}
