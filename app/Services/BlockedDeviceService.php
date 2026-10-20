<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * حظر/فك حظر جهاز بعينه (X-Device-Id). الفحص بيتعمل على كل ريكوست API (UipDeviceRestrictionMiddleware)
 * فالقائمة متكاشية 30 ثانية، وبتتمسح فورًا لما الأدمن يحظر أو يفك الحظر على نفس السيرفر.
 * أي خطأ (جدول لسه ما اتعملش migration مثلًا) = الفحص بيعدّي، عمره ما يقفل المنصة.
 */
class BlockedDeviceService
{
    private const CACHE_KEY = 'blocked_devices:set';

    public static function normalize(?string $deviceId): string
    {
        return substr(trim((string) $deviceId), 0, 64);
    }

    public function isBlocked(?string $deviceId): bool
    {
        $id = self::normalize($deviceId);
        if ($id === '') {
            return false;
        }
        try {
            $set = Cache::remember(self::CACHE_KEY, 30, fn () => DB::table('blocked_devices')->pluck('device_id')->all());
            return in_array($id, $set, true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return DB::table('blocked_devices as b')
            ->leftJoin('users as u', 'u.id', '=', 'b.blocked_by')
            ->orderByDesc('b.id')
            ->select('b.id', 'b.device_id', 'b.label', 'b.reason', 'b.created_at', 'u.full_name as blocked_by_name')
            ->get()->map(fn ($r) => (array) $r)->all();
    }

    /** @return array<string,int> device_id => blocked_devices.id */
    public function idsFor(array $deviceIds): array
    {
        $deviceIds = array_values(array_filter(array_map([self::class, 'normalize'], $deviceIds)));
        if (!$deviceIds) {
            return [];
        }
        return DB::table('blocked_devices')->whereIn('device_id', $deviceIds)->pluck('id', 'device_id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return int id of the (new or existing) block row */
    public function block(string $deviceId, ?string $label, ?string $reason, $adminId): int
    {
        $deviceId = self::normalize($deviceId);
        $existing = DB::table('blocked_devices')->where('device_id', $deviceId)->value('id');
        if ($existing) {
            return (int) $existing;
        }
        $id = (int) DB::table('blocked_devices')->insertGetId([
            'device_id'  => $deviceId,
            'label'      => $label !== null && $label !== '' ? mb_substr($label, 0, 255) : null,
            'reason'     => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'blocked_by' => $adminId ?: null,
            'created_at' => now(),
        ]);
        Cache::forget(self::CACHE_KEY);
        return $id;
    }

    public function unblock(int $id): ?string
    {
        $row = DB::table('blocked_devices')->where('id', $id)->first();
        if (!$row) {
            return null;
        }
        DB::table('blocked_devices')->where('id', $id)->delete();
        Cache::forget(self::CACHE_KEY);
        return (string) $row->device_id;
    }
}
