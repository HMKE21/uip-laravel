<?php

namespace App\Console\Commands;

use App\Services\ProfilePhotoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AvatarsProtect extends Command
{
    protected $signature = 'avatars:protect {--limit=0 : max users this run}';
    protected $description = 'Move existing full-size profile photos to private storage and replace the public file with a small thumbnail.';

    public function handle(ProfilePhotoService $photos): int
    {
        $q = DB::table('users')->whereNotNull('avatar_path')->where('avatar_path', '!=', '')
            ->whereNotExists(fn ($w) => $w->select(DB::raw(1))->from('user_profile_photos as p')->whereColumn('p.user_id', 'users.id'))
            ->orderBy('id');
        if ((int) $this->option('limit') > 0) {
            $q->limit((int) $this->option('limit'));
        }
        $done = 0;
        $skipped = 0;
        foreach ($q->get(['id', 'avatar_path']) as $u) {
            try {
                $thumb = $photos->protectExisting((int) $u->id, (string) $u->avatar_path);
            } catch (\Throwable $e) {
                $thumb = null;
            }
            if ($thumb) {
                DB::table('users')->where('id', $u->id)->update(['avatar_path' => $thumb]);
                $done++;
            } else {
                $skipped++;
            }
        }
        $this->info("Protected {$done} photo(s); skipped {$skipped}.");
        return self::SUCCESS;
    }
}
