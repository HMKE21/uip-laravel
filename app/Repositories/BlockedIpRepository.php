<?php

namespace App\Repositories;

use App\Models\BlockedIp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/BlockedIpRepository.php القديمة — all() / unblock() / isBlocked() / block().
 *
 * إضافات بند 1 (Detection Engine): الحظر بقى ممكن ينتهي (expires_at) ويتعلّم مصدره (manual|auto) والقاعدة اللي حظرته (rule_id).
 * والأهم: isBlockedCached() بتتنادى من UipNetworkRestrictionMiddleware على كل ريكوست — قبل كده الجدول كان بيتخزّن بس
 * ومفيش حاجة بتطبّقه فعليًا.
 */
class BlockedIpRepository
{
    private const CACHE_TTL = 30;

    /** صف نشط = مفيش expires_at أو لسه ماعدّاش. */
    private function active($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    private function hasExpiryColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = \Illuminate\Support\Facades\Schema::hasColumn('blocked_ips', 'expires_at');
            } catch (\Throwable) {
                $has = false;
            }
        }
        return $has;
    }

    private static function cacheKey(string $ip): string
    {
        return 'blkip:' . \App\Services\Tenancy\TenantContext::ns() . sha1($ip);
    }

    public static function forget(string $ip): void
    {
        try {
            Cache::forget(self::cacheKey($ip));
        } catch (\Throwable) {
        }
    }

    /** يحترم الانتهاء. */
    public function isBlocked(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        $q = BlockedIp::where('ip_address', $ip);
        if ($this->hasExpiryColumn()) {
            $q = $this->active($q);
        }
        return $q->exists();
    }

    /** نسخة بكاش قصير (30 ثانية) للـ middleware. الحظر/الفك بيمسحوا الكاش فيسري فورًا. */
    public function isBlockedCached(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        try {
            return (bool) Cache::remember(self::cacheKey($ip), self::CACHE_TTL, fn () => $this->isBlocked($ip) ? 1 : 0);
        } catch (\Throwable) {
            return $this->isBlocked($ip);
        }
    }

    /** منقولة من BlockedIpRepository::block() القديمة (حظر يدوي، دائم). */
    public function block(string $ip, ?string $reason, $adminId): bool
    {
        if ($ip === '' || $this->isBlocked($ip)) {
            return false;
        }
        // صف منتهي لسه موجود (unique على ip_address): نمسحه الأول.
        BlockedIp::where('ip_address', $ip)->delete();
        BlockedIp::create([
            'ip_address' => $ip,
            'reason'     => $reason,
            'blocked_by' => $adminId,
        ]);
        self::forget($ip);
        return true;
    }

    /** حظر تلقائي من قاعدة كشف. $expires = null => دائم. */
    public function blockAuto(string $ip, string $reason, int $ruleId, $expires): bool
    {
        if ($ip === '' || $this->isBlocked($ip)) {
            return false;
        }
        BlockedIp::where('ip_address', $ip)->delete();
        $row = new BlockedIp([
            'ip_address' => $ip,
            'reason'     => mb_substr($reason, 0, 255),
            'blocked_by' => null,
        ]);
        if ($this->hasExpiryColumn()) {
            $row->setAttribute('expires_at', $expires);
            $row->setAttribute('source', 'auto');
            $row->setAttribute('rule_id', $ruleId);
        }
        $row->save();
        self::forget($ip);
        return true;
    }

    /** @return array<int,array<string,mixed>> الحظر النشط بس (المنتهي مش بيظهر). */
    public function all(): array
    {
        $q = DB::table('blocked_ips as b')
            ->leftJoin('users as u', 'u.id', '=', 'b.blocked_by')
            ->select('b.*', 'u.full_name as blocked_by_name');
        if ($this->hasExpiryColumn()) {
            $q->where(function ($w) {
                $w->whereNull('b.expires_at')->orWhere('b.expires_at', '>', now());
            });
        }
        return $q->orderByDesc('b.created_at')->get()->map(fn ($r) => (array) $r)->all();
    }

    /** @return array<int,array<string,mixed>> الحظر التلقائي النشط، مع اسم القاعدة. */
    public function activeAuto(): array
    {
        if (!$this->hasExpiryColumn()) {
            return [];
        }
        $q = DB::table('blocked_ips as b')
            ->leftJoin('security_detection_rules as r', 'r.id', '=', 'b.rule_id')
            ->where('b.source', 'auto')
            ->where(function ($w) {
                $w->whereNull('b.expires_at')->orWhere('b.expires_at', '>', now());
            })
            ->select('b.id', 'b.ip_address', 'b.reason', 'b.created_at', 'b.expires_at', 'b.rule_id', 'r.name_en as rule_name', 'r.name_ar as rule_name_ar')
            ->orderByDesc('b.created_at');
        return $q->get()->map(fn ($r) => (array) $r)->all();
    }

    public function unblock(int $id): bool
    {
        $row = BlockedIp::find($id);
        if (!$row) {
            return false;
        }
        $ip = (string) $row->ip_address;
        $ok = (bool) $row->delete();
        self::forget($ip);
        return $ok;
    }

    /** يمسح الصفوف المنتهية. @return int عددها */
    public function purgeExpired(): int
    {
        if (!$this->hasExpiryColumn()) {
            return 0;
        }
        return (int) DB::table('blocked_ips')->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
    }
}
