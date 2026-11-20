<?php

namespace App\Services\Mail;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** UIPE Mail — سجل تدقيق (المرحلة 7). مفيهوش موضوع ولا نص الرسائل: إجراءات وأرقام بس. التسجيل عمره ما بيكسر العملية الأصلية. */
class MailAuditService
{
    /** @param array{university_id?:?int,tenant_id?:?int,actor_user_id?:?int,actor_role?:?string,account_id?:?int,target_type?:?string,target_id?:int|string|null,meta?:?array,ip?:?string} $ctx */
    public function record(string $action, ?string $summary = null, array $ctx = []): void
    {
        try {
            DB::table('mail_audit_logs')->insert([
                'university_id' => $ctx['university_id'] ?? null, 'tenant_id' => $ctx['tenant_id'] ?? null,
                'actor_user_id' => $ctx['actor_user_id'] ?? null, 'actor_role' => $ctx['actor_role'] ?? null,
                'account_id' => $ctx['account_id'] ?? null, 'action' => mb_substr($action, 0, 60),
                'target_type' => $ctx['target_type'] ?? null, 'target_id' => isset($ctx['target_id']) ? mb_substr((string) $ctx['target_id'], 0, 60) : null,
                'summary' => $summary !== null ? mb_substr($summary, 0, 250) : null,
                'meta' => isset($ctx['meta']) ? json_encode($ctx['meta'], JSON_UNESCAPED_UNICODE) : null,
                'ip_address' => $ctx['ip'] ?? null, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('UIPE Mail: audit write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** $universityId = null → كل المنصة (أدمن المنصة بس). */
    public function list(?int $universityId, array $f, int $page = 1, int $perPage = 30): array
    {
        $q = DB::table('mail_audit_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.actor_user_id')->leftJoin('mail_accounts as a', 'a.id', '=', 'l.account_id');
        if ($universityId !== null) {
            $q->where('l.university_id', $universityId);
        }
        if (!empty($f['action'])) {
            $q->where('l.action', 'like', str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['action']) . '%');
        }
        if (!empty($f['account_id'])) {
            $q->where('l.account_id', (int) $f['account_id']);
        }
        if (!empty($f['actor_user_id'])) {
            $q->where('l.actor_user_id', (int) $f['actor_user_id']);
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (!empty($f[$k]) && strtotime((string) $f[$k]) !== false) {
                $q->where('l.created_at', $op, Carbon::parse((string) $f[$k])->{$k === 'from' ? 'startOfDay' : 'endOfDay'}());
            }
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $f['q'])) . '%';
            $q->where(fn ($w) => $w->where('l.summary', 'like', $like)->orWhere('a.address', 'like', $like)->orWhere('l.action', 'like', $like));
        }
        $perPage = min(100, max(5, $perPage));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('l.id')->forPage(max(1, $page), $perPage)
            ->get(['l.id', 'l.university_id', 'l.actor_user_id', 'l.actor_role', 'l.account_id', 'l.action', 'l.target_type', 'l.target_id', 'l.summary', 'l.meta', 'l.ip_address', 'l.created_at', 'u.full_name as actor_name', 'a.address as account_address']);
        return [
            'items' => $rows->map(fn ($r) => [
                'id' => (int) $r->id, 'university_id' => $r->university_id ? (int) $r->university_id : null,
                'actor' => ['id' => $r->actor_user_id ? (int) $r->actor_user_id : null, 'name' => $r->actor_name, 'role' => $r->actor_role],
                'account' => $r->account_id ? ['id' => (int) $r->account_id, 'address' => $r->account_address] : null,
                'action' => $r->action, 'target_type' => $r->target_type, 'target_id' => $r->target_id, 'summary' => $r->summary,
                'meta' => $r->meta ? json_decode((string) $r->meta, true) : null, 'ip' => $r->ip_address,
                'created_at' => Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
            ])->all(),
            'total' => $total, 'page' => max(1, $page), 'per_page' => $perPage,
        ];
    }

    public function prune(): int
    {
        $days = (int) config('mailbox.audit_retention_days', 365);
        return $days > 0 ? DB::table('mail_audit_logs')->where('created_at', '<', now()->subDays($days))->delete() : 0;
    }
}
