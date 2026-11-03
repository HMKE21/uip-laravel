<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * خصوصية البروفايل في مكان واحد: قفل البروفايل، مين يشوف قوايم الأصدقاء/المتابعين، ومين ينزّل الصورة الشخصية.
 *
 * "قريب" = صديق (اتصال مقبول) أو متابع لصاحب البروفايل. صاحب البروفايل دايمًا مسموحله.
 * الجداول/الأعمدة الجديدة لسه ممكن تكون مش موجودة (قبل الـ migration) فكل القراءات fail-open بقيم افتراضية آمنة للتشغيل.
 */
class ProfilePrivacyService
{
    public const LISTS = ['everyone', 'contacts', 'me'];
    public const DOWNLOAD = ['nobody', 'contacts', 'everyone'];

    /** @var array<int,array{locked:bool,lists:string,download:string}> */
    private array $cache = [];

    public function forUser(int $userId): array
    {
        if (!isset($this->cache[$userId])) {
            $out = ['locked' => false, 'lists' => 'everyone', 'download' => 'nobody'];
            try {
                $r = DB::table('user_network_settings')->where('user_id', $userId)->first(['profile_locked', 'lists_visibility', 'photo_download']);
                if ($r) {
                    $out['locked'] = (bool) $r->profile_locked;
                    $out['lists'] = in_array($r->lists_visibility, self::LISTS, true) ? $r->lists_visibility : 'everyone';
                    $out['download'] = in_array($r->photo_download, self::DOWNLOAD, true) ? $r->photo_download : 'nobody';
                }
            } catch (\Throwable $e) {
                // الأعمدة لسه ماتضافتش (migration ماتشغلتش) — القيم الافتراضية.
            }
            $this->cache[$userId] = $out;
        }
        return $this->cache[$userId];
    }

    public function forget(int $userId): void
    {
        unset($this->cache[$userId]);
    }

    public function isLocked(int $userId): bool
    {
        return $this->forUser($userId)['locked'];
    }

    /** صديق أو متابع لـ $ownerId؟ */
    public function isCloseTo(int $viewerId, int $ownerId): bool
    {
        if ($viewerId === $ownerId) {
            return true;
        }
        $follows = DB::table('post_follows')->where('follower_id', $viewerId)->where('followee_id', $ownerId)->exists();
        if ($follows) {
            return true;
        }
        return DB::table('user_connections')->where('status', 'accepted')->where(function ($w) use ($viewerId, $ownerId) {
            $w->where(fn ($x) => $x->where('requester_id', $viewerId)->where('addressee_id', $ownerId))
                ->orWhere(fn ($x) => $x->where('requester_id', $ownerId)->where('addressee_id', $viewerId));
        })->exists();
    }

    /** هل المشاهد يقدر يشوف محتوى البروفايل (منشورات/صور/نبذة/قوايم) — مش مقفول، أو قريب. */
    public function canSeeProfile(?int $viewerId, int $ownerId): bool
    {
        if ($viewerId !== null && $viewerId === $ownerId) {
            return true;
        }
        if (!$this->isLocked($ownerId)) {
            return true;
        }
        return $viewerId !== null && $this->isCloseTo($viewerId, $ownerId);
    }

    /** قوايم الأصدقاء/المتابعين/المتابَعين. */
    public function canSeeLists(?int $viewerId, int $ownerId): bool
    {
        if ($viewerId !== null && $viewerId === $ownerId) {
            return true;
        }
        if (!$this->canSeeProfile($viewerId, $ownerId)) {
            return false;
        }
        return match ($this->forUser($ownerId)['lists']) {
            'me'       => false,
            'contacts' => $viewerId !== null && $this->isCloseTo($viewerId, $ownerId),
            default    => true,
        };
    }

    /** تنزيل الصورة الشخصية (غير الاطلاع عليها). */
    public function canDownloadPhoto(?int $viewerId, int $ownerId): bool
    {
        if ($viewerId !== null && $viewerId === $ownerId) {
            return true;
        }
        return match ($this->forUser($ownerId)['download']) {
            'everyone' => $viewerId !== null,
            'contacts' => $viewerId !== null && $this->isCloseTo($viewerId, $ownerId),
            default    => false,
        };
    }

    /** SQL fragment لـ PostVisibility: مؤلف المنشور (p.user_id) مقفول البروفايل ومشاهد مش قريب منه. */
    public const SQL_NOT_LOCKED_OUT = '(NOT EXISTS (SELECT 1 FROM user_network_settings ns WHERE ns.user_id = p.user_id AND ns.profile_locked = 1)
        OR EXISTS (SELECT 1 FROM post_follows lf WHERE lf.follower_id = ? AND lf.followee_id = p.user_id)
        OR EXISTS (SELECT 1 FROM user_connections lk WHERE lk.status = \'accepted\' AND ((lk.requester_id = ? AND lk.addressee_id = p.user_id) OR (lk.addressee_id = ? AND lk.requester_id = p.user_id))))';
}
