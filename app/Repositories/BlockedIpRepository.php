<?php

namespace App\Repositories;

use App\Models\BlockedIp;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/BlockedIpRepository.php القديمة —
 * all() (بند 25 batch 1، لداشبورد الأمان) + unblock() (بند 25 batch 4،
 * Logs — SecurityLogsApiController::unblockIp()، بتستخدم App\Models\
 * BlockedIp زي القديمة بالظبط). isBlocked()/block() اتوصلوا دلوقتي في
 * بند 25 batch 9 (Admin > Users > blockIp) — AuthService لسه مش
 * بتستخدمهم (نفس فجوة user_sessions الموثّقة في UserSessionRepository)،
 * بس الميثودز موجودة زي القديمة بالظبط لأن Users API محتاجاها.
 */
class BlockedIpRepository
{
    /** منقولة من BlockedIpRepository::isBlocked() القديمة بالظبط. */
    public function isBlocked(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        return BlockedIp::where('ip_address', $ip)->exists();
    }

    /** منقولة من BlockedIpRepository::block() القديمة بالظبط. */
    public function block(string $ip, ?string $reason, $adminId): bool
    {
        if ($ip === '' || $this->isBlocked($ip)) {
            return false;
        }
        BlockedIp::create([
            'ip_address' => $ip,
            'reason'     => $reason,
            'blocked_by' => $adminId,
        ]);
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return DB::table('blocked_ips as b')
            ->leftJoin('users as u', 'u.id', '=', 'b.blocked_by')
            ->select('b.*', 'u.full_name as blocked_by_name')
            ->orderByDesc('b.created_at')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    public function unblock(int $id): bool
    {
        $row = BlockedIp::find($id);
        if (!$row) {
            return false;
        }
        return (bool) $row->delete();
    }
}
