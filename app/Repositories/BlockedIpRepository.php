<?php

namespace App\Repositories;

use App\Models\BlockedIp;
use App\Services\NetworkControlService;
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
            if (NetworkControlService::isCidr($ip)) {
                Cache::forget('netctl:cidrblocks:' . \App\Services\Tenancy\TenantContext::ns());
            }
        } catch (\Throwable) {
        }
    }

    /** يحترم الانتهاء + النطاقات (CIDR) + قائمة الموثوقين (لا تُحظر أبدًا). */
    public function isBlocked(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        if (app(NetworkControlService::class)->isTrusted($ip)) {
            return false;
        }
        $q = BlockedIp::where('ip_address', $ip);
        if ($this->hasExpiryColumn()) {
            $q = $this->active($q);
        }
        if ($q->exists()) {
            return true;
        }
        foreach ($this->activeCidrs() as $cidr) {
            if (NetworkControlService::matches($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] نطاقات CIDR المحظورة النشطة (كاش 30 ثانية). */
    private function activeCidrs(): array
    {
        try {
            return Cache::remember('netctl:cidrblocks:' . \App\Services\Tenancy\TenantContext::ns(), self::CACHE_TTL, function () {
                $q = BlockedIp::where('ip_address', 'like', '%/%');
                if ($this->hasExpiryColumn()) {
                    $q = $this->active($q);
                }
                return $q->pluck('ip_address')->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /** الـ IP (مش CIDR) موثوق؟ — بنرفض حظره من أي مصدر. */
    private function isTrustedEntry(string $ip): bool
    {
        return !NetworkControlService::isCidr($ip) && app(NetworkControlService::class)->isTrusted($ip);
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
        if ($ip === '' || $this->isTrustedEntry($ip) || $this->isBlocked($ip)) {
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

    /** حظر يدوي مؤقت (من مركز التحقيق). $expires = null => دائم. */
    public function blockTimed(string $ip, ?string $reason, $adminId, $expires): bool
    {
        if ($ip === '' || $this->isTrustedEntry($ip) || $this->isBlocked($ip)) {
            return false;
        }
        BlockedIp::where('ip_address', $ip)->delete();
        $row = new BlockedIp(['ip_address' => $ip, 'reason' => $reason, 'blocked_by' => $adminId]);
        if ($this->hasExpiryColumn()) {
            $row->setAttribute('expires_at', $expires);
            $row->setAttribute('source', 'manual');
        }
        $row->save();
        self::forget($ip);
        return true;
    }

    /** @param string[] $ips @return string[] اللي منهم محظور حاليًا (نشط). */
    public function activeAmong(array $ips): array
    {
        if (!$ips) {
            return [];
        }
        $q = BlockedIp::whereIn('ip_address', $ips);
        if ($this->hasExpiryColumn()) {
            $q = $this->active($q);
        }
        return $q->pluck('ip_address')->all();
    }

    /** حظر تلقائي من قاعدة كشف. $expires = null => دائم. */
    public function blockAuto(string $ip, string $reason, int $ruleId, $expires): bool
    {
        if ($ip === '' || $this->isTrustedEntry($ip) || $this->isBlocked($ip)) {
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

    /**
     * حظر من الصفحة اليدوية / الاستيراد الجماعي / حد المعدل. يقبل IP أو CIDR.
     * @param string $source manual | import | rate
     * @return string 'added' | 'exists' | 'trusted'
     */
    public function blockEntry(string $entry, ?string $reason, $adminId, $expires, string $source = 'manual'): string
    {
        if ($this->isTrustedEntry($entry)) {
            return 'trusted';
        }
        $existing = BlockedIp::where('ip_address', $entry)->first();
        if ($existing) {
            $stillActive = !$this->hasExpiryColumn() || $existing->expires_at === null || $existing->expires_at->isFuture();
            if ($stillActive) {
                return 'exists';
            }
            $existing->delete();
        }
        $row = new BlockedIp(['ip_address' => $entry, 'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null, 'blocked_by' => $adminId]);
        if ($this->hasExpiryColumn()) {
            $row->setAttribute('expires_at', $expires);
            $row->setAttribute('source', $source);
        }
        $row->save();
        self::forget($entry);
        return 'added';
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
        $n = (int) DB::table('blocked_ips')->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('network_trusted_ips')) {
                $n += (int) DB::table('network_trusted_ips')->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
            }
        } catch (\Throwable) {
        }
        NetworkControlService::forgetCaches();
        return $n;
    }
}
