<?php

namespace App\Services;

use App\Repositories\ExamAttemptRepository;
use App\Support\DriveException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * إعادة تهيئة "جهازي" بمهلة تراجع.
 *   reset()    : ينقل كل الملفات للسلة + يحفظ لقطة من الإعدادات/الويدجت/باسورد القفل + يرجّع الإعدادات للافتراضي. بيسجّل مهلة 24 ساعة.
 *   undo()     : يرجّع الملفات من السلة + الإعدادات من اللقطة.
 *   finalize() : مسح نهائي (بعد المهلة، أو لما الطالب يعمل إعادة تهيئة تانية).
 *   examActive : امتحان شغّال = ممنوع (المسودات المحلية للامتحان ممكن تضيع).
 */
class DesktopResetService
{
    public const UNDO_HOURS = 24;

    public function __construct(private DriveService $drive)
    {
    }

    public function examActive(int $uid): bool
    {
        try {
            $studentId = DB::table('students')->where('user_id', $uid)->value('id');
            if (!$studentId) {
                return false;
            }

            return app(ExamAttemptRepository::class)->activeAttemptForStudent((int) $studentId) !== null;
        } catch (\Throwable $e) {
            Log::warning('desktop reset: exam check failed', ['uid' => $uid, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** @return array{pending:bool,expires_at:?string,seconds_left:int,count:int} */
    public function status(int $uid): array
    {
        $row = DB::table('desktop_resets')->where('user_id', $uid)->first();
        if ($row && strtotime((string) $row->expires_at) <= time()) {
            $this->finalize($uid);
            $row = null;
        }
        if (!$row) {
            return ['pending' => false, 'expires_at' => null, 'seconds_left' => 0, 'count' => 0];
        }
        $roots = json_decode((string) $row->roots, true);

        return [
            'pending'      => true,
            'expires_at'   => date('c', strtotime((string) $row->expires_at)),
            'seconds_left' => max(0, strtotime((string) $row->expires_at) - time()),
            'count'        => is_array($roots) ? count($roots) : 0,
        ];
    }

    /** @return int عدد العناصر اللي اتنقلت للسلة */
    public function reset(int $uid): int
    {
        $this->finalize($uid); // إعادة تهيئة تانية = المهلة القديمة بتتقفل ومسحها بيبقى نهائي

        $widgets = Schema::hasTable('desktop_widgets') ? DB::table('desktop_widgets')->where('user_id', $uid)->first() : null;
        $set = DB::table('desktop_settings')->where('user_id', $uid)->first();
        $snapshot = [
            'settings' => $set ? [
                'prefs' => $set->prefs, 'icons' => $set->icons, 'mode' => $set->mode, 'wallpaper' => $set->wallpaper,
                'wp_hash' => $set->wp_hash, 'lock_hash' => $set->lock_hash,
            ] : null,
            'widgets' => $widgets ? ['items' => $widgets->items] : null,
        ];

        $roots = $this->drive->softResetAll($uid);

        DB::table('desktop_resets')->insert([
            'user_id'    => $uid,
            'roots'      => json_encode($roots),
            'snapshot'   => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'expires_at' => now()->addHours(self::UNDO_HOURS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $now = (int) (microtime(true) * 1000);
        // updated_ms = الآن: أي جهاز تاني لسه عنده إعدادات قديمة بياخد النسخة الفاضية بدل ما يرفعها تاني
        DB::table('desktop_settings')->where('user_id', $uid)->update([
            'prefs' => null, 'icons' => null, 'mode' => null, 'wallpaper' => null, 'wp_hash' => null,
            'lock_hash' => null, 'lock_changed_at' => now(), 'updated_ms' => $now, 'updated_at' => now(),
        ]);
        if ($widgets) {
            DB::table('desktop_widgets')->where('user_id', $uid)->update(['items' => null, 'updated_ms' => $now, 'updated_at' => now()]);
        }

        return count($roots);
    }

    /** @return int عدد الملفات/المجلدات الجذرية اللي اترجعت */
    public function undo(int $uid): int
    {
        $row = DB::table('desktop_resets')->where('user_id', $uid)->first();
        if (!$row || strtotime((string) $row->expires_at) <= time()) {
            if ($row) {
                $this->finalize($uid);
            }
            throw new DriveException('There is nothing to undo.', 404);
        }

        $restored = 0;
        foreach ((array) json_decode((string) $row->roots, true) as $id) {
            try { // جذر اتمسح يدويًا من السلة في الأثناء: بنكمّل على الباقي
                $this->drive->restore($uid, [(int) $id]);
                $restored++;
            } catch (DriveException $e) {
                // ignore
            }
        }

        $snap = json_decode((string) $row->snapshot, true) ?: [];
        $now = (int) (microtime(true) * 1000);
        if (!empty($snap['settings'])) {
            DB::table('desktop_settings')->where('user_id', $uid)->update($snap['settings'] + ['lock_changed_at' => now(), 'updated_ms' => $now, 'updated_at' => now()]);
        }
        if (!empty($snap['widgets']) && Schema::hasTable('desktop_widgets')) {
            DB::table('desktop_widgets')->where('user_id', $uid)->update(['items' => $snap['widgets']['items'], 'updated_ms' => $now, 'updated_at' => now()]);
        }
        DB::table('desktop_resets')->where('user_id', $uid)->delete();

        return $restored;
    }

    /** مسح نهائي لملفات إعادة تهيئة (انتهت مهلتها أو اتعملت واحدة بعدها). */
    public function finalize(int $uid): void
    {
        $row = DB::table('desktop_resets')->where('user_id', $uid)->first();
        if (!$row) {
            return;
        }
        $this->drive->finalizeReset($uid, array_map('intval', (array) json_decode((string) $row->roots, true)));
        DB::table('desktop_resets')->where('user_id', $uid)->delete();
    }

    public function finalizeExpired(): int
    {
        $n = 0;
        foreach (DB::table('desktop_resets')->where('expires_at', '<=', now())->pluck('user_id') as $uid) {
            try {
                $this->finalize((int) $uid);
                $n++;
            } catch (\Throwable $e) {
                Log::error('desktop reset finalize failed', ['uid' => $uid, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }
}
