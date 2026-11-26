<?php

namespace App\Services;

use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * قفل "سجل النشاط" في Admin → My PC Control (نفس فكرة إشراف الرسايل).
 *
 * - السجل (صفوف audit_logs اللي action بتاعها admin.drive.*) بيرجع من الـ API مخفي دايمًا — الإخفاء على السيرفر.
 * - العرض والحذف الاتنين محتاجين المفتاح السري من .env: SECRET_KEY_TO_PC_CONTROL_ACTIVITY_LOG (+ سبب).
 *   بعد المفتاح الصحيح بيتفتح تصريح لمدة قصيرة (DRIVE_AUDIT_GRANT_MINUTES) وبينتهي لوحده أو بزرار "قفل الآن".
 * - مفتاح غلط: 5 محاولات كل 10 دقايق لكل أدمن، وكل محاولة بتتسجّل.
 * - المفتاح مش متضبط في .env = مقفول بالكامل.
 * - كل فتح/قفل/محاولة غلط/حذف بيتسجّل بـ action = admin.drive_audit.* (بـ underscore بعد drive، فمش بيتمسح بالحذف
 *   ومش بيظهر في السجل نفسه): يعني حذف السجل بيسيب أثر دايم في audit_logs العام.
 */
class DriveAuditAccessService
{
    public const LIKE = 'admin.drive.%';

    public function __construct(private AuditLogService $audit)
    {
    }

    public function keyConfigured(): bool
    {
        return $this->expectedKey() !== '';
    }

    private function expectedKey(): string
    {
        return trim((string) config('drive.audit_access.key', ''));
    }

    private function grantMinutes(): int
    {
        return max(5, min(120, (int) config('drive.audit_access.grant_minutes', 15)));
    }

    /** @return array<string,mixed> */
    public function status(int $adminId): array
    {
        $g = $this->activeGrant($adminId);

        return [
            'configured' => $this->keyConfigured(),
            'unlocked'   => $g !== null,
            'grant'      => $g ? [
                'id'           => (int) $g->id,
                'reason'       => $g->reason,
                'expires_at'   => (string) $g->expires_at,
                'seconds_left' => max(0, Carbon::parse($g->expires_at)->timestamp - now()->timestamp),
            ] : null,
            'limits' => ['default_minutes' => $this->grantMinutes(), 'max_minutes' => 120],
        ];
    }

    /** أي خطأ في قراءة التصاريح = مقفول. */
    public function isUnlocked(int $adminId): bool
    {
        try {
            return $this->activeGrant($adminId) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function activeGrant(int $adminId): ?object
    {
        return DB::table('drive_audit_grants')
            ->where('admin_id', $adminId)->whereNull('revoked_at')->where('expires_at', '>', now())
            ->orderByDesc('id')->first();
    }

    /**
     * @return array<string,mixed> status بعد الفتح
     * @throws \InvalidArgumentException بيانات ناقصة (422)
     * @throws \RuntimeException المفتاح غلط (403) / كتر المحاولات (429) / المفتاح مش متضبط (503)
     */
    public function unlock(int $adminId, string $key, string $reason, ?int $minutes = null, ?string $ip = null): array
    {
        if (!$this->keyConfigured()) {
            throw new \RuntimeException('The secret key for the activity log is not configured on the server (.env).', 503);
        }

        $throttle = 'drive-audit-key:' . $adminId;
        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            $this->audit->record($adminId, 'admin.drive_audit.throttled', 'DriveAudit', null, null, null, $ip);
            throw new \RuntimeException('Too many wrong keys. Try again in ' . ceil(RateLimiter::availableIn($throttle) / 60) . ' minute(s).', 429);
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new \InvalidArgumentException('Write the reason for opening the activity log (at least 5 characters).');
        }
        $minutes = $minutes && $minutes > 0 ? $minutes : $this->grantMinutes();
        $minutes = max(5, min(120, $minutes));

        // مقارنة ثابتة الزمن، بعد التحققات الشكلية عشان محاولة غلط شكلاً متتحسبش ضد الأدمن.
        if (!hash_equals($this->expectedKey(), trim($key))) {
            RateLimiter::hit($throttle, 600);
            $this->audit->record($adminId, 'admin.drive_audit.denied', 'DriveAudit', null, null, null, $ip);
            throw new \RuntimeException('Wrong secret key.', 403);
        }
        RateLimiter::clear($throttle);

        // تصريح واحد ساري لكل أدمن: القديم بيتقفل.
        DB::table('drive_audit_grants')->where('admin_id', $adminId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $id = (int) DB::table('drive_audit_grants')->insertGetId([
            'admin_id'   => $adminId,
            'reason'     => mb_substr($reason, 0, 255),
            'ip'         => $ip ? mb_substr($ip, 0, 64) : null,
            'expires_at' => now()->addMinutes($minutes),
            'created_at' => now(),
        ]);
        $this->audit->record($adminId, 'admin.drive_audit.unlocked', 'DriveAudit', $id, null, ['minutes' => $minutes, 'reason' => mb_substr($reason, 0, 255)], $ip);

        return $this->status($adminId);
    }

    public function lock(int $adminId, ?string $ip = null): void
    {
        $n = DB::table('drive_audit_grants')->where('admin_id', $adminId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        if ($n) {
            $this->audit->record($adminId, 'admin.drive_audit.locked', 'DriveAudit', null, null, null, $ip);
        }
    }

    /**
     * حذف صفوف السجل (admin.drive.* بس — مش أي سجل تاني). بـ ids محددة أو بكل اللي بيطابق الفلتر.
     *
     * @param  array{ids?:array<int>,action?:string,subject_id?:int|string|null,all?:bool} $in
     * @return int عدد الصفوف المحذوفة
     */
    public function purge(int $adminId, array $in, ?string $ip = null): int
    {
        $q = AuditLog::query()->where('action', 'like', self::LIKE);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])), fn ($i) => $i > 0)));

        if ($ids) {
            $q->whereIn('id', $ids);
            $scope = ['mode' => 'selected', 'ids_count' => count($ids)];
        } elseif (!empty($in['all'])) {
            // نفس فلاتر شاشة العرض (action prefix + المستخدم الهدف).
            if (!empty($in['action'])) {
                $q->where('action', 'like', 'admin.drive.' . str_replace(['%', '_'], ['\\%', '\\_'], preg_replace('/^admin\.drive\./', '', (string) $in['action'])) . '%');
            }
            if (!empty($in['subject_id'])) {
                $q->where('subject_type', 'DriveUser')->where('subject_id', (int) $in['subject_id']);
            }
            $scope = ['mode' => 'all_matching', 'action' => $in['action'] ?? null, 'subject_id' => $in['subject_id'] ?? null];
        } else {
            return 0;
        }

        $deleted = (int) $q->delete();
        $this->audit->record($adminId, 'admin.drive_audit.deleted', 'DriveAudit', null, null, $scope + ['deleted' => $deleted], $ip);

        return $deleted;
    }
}
