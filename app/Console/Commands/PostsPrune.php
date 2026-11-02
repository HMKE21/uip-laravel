<?php

namespace App\Console\Commands;

use App\Services\PostMediaService;
use App\Services\PostSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PostsPrune extends Command
{
    protected $signature = 'posts:prune';
    protected $description = 'Delete orphan post uploads, hard-delete posts removed 30+ days ago (with files), and decay stale interests/views.';

    public function handle(PostMediaService $media): int
    {
        // 1) ملفات مرفوعة ومتربطتش بمنشور
        $orphans = DB::table('post_media')->whereNull('post_id')
            ->where('created_at', '<', now()->subHours((int) PostSettings::get('max_orphan_hours')))->limit(500)->get();
        foreach ($orphans as $row) {
            $media->destroyRow($row);
        }

        // 2) منشورات محذوفة من +30 يوم: ملفاتها ثم الصف (باقي الجداول cascade)
        $old = DB::table('posts')->whereNotNull('deleted_at')->where('deleted_at', '<', now()->subDays(30))->limit(200)->pluck('id');
        foreach ($old as $postId) {
            foreach (DB::table('post_media')->where('post_id', $postId)->get() as $row) {
                $media->destroyRow($row);
            }
            DB::table('posts')->where('id', $postId)->delete();
        }

        // 3) اهتمامات باهتة ومشاهدات قديمة
        $a = DB::table('user_post_interests')->where('weight', '<', 0.05)->orWhere('updated_at', '<', now()->subDays(120))->delete();
        $b = DB::table('post_views')->where('last_viewed_at', '<', now()->subDays(90))->delete();

        $this->info("Pruned {$orphans->count()} orphan file(s), {$old->count()} post(s), {$a} interest(s), {$b} view(s).");
        return self::SUCCESS;
    }
}
