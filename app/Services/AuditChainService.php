<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\AuditChain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * سلسلة هاش audit_logs (بند 6):
 *  seal()        — بيكتب صف جديد متختم (بيتنادى من AuditLogService::record).
 *  sealPending() — بيختم الصفوف القديمة/اللي اتكتبت من غير ختم (backfill) بترتيب الـ id.
 *  verify()      — بيعيد حساب السلسلة كلها ويقارنها بالمخزّن + نقاط التحقق + المراسي + راس السلسلة.
 *  checkpoint()  — نقطة تحقق موقّعة (HMAC) بآخر هاش: بتكشف حتى لو حد أعاد حساب السلسلة كلها.
 *
 * ملحوظة: الختم بيثبت السلامة من لحظة الختم؛ الصفوف القديمة بتتختم وقت الـ backfill.
 */
class AuditChainService
{
    private const CHUNK = 1000;

    /** @param array<string,mixed> $attrs user_id/action/subject_type/subject_id/old_values/new_values/ip_address */
    public function seal(array $attrs): AuditLog
    {
        return DB::transaction(function () use ($attrs) {
            $state = $this->lockedState();
            $seq = (int) $state->last_seq + 1;
            $row = [
                'chain_seq'    => $seq,
                'actor_ref'    => isset($attrs['user_id']) ? (int) $attrs['user_id'] : null,
                'action'       => (string) $attrs['action'],
                'subject_type' => $attrs['subject_type'] ?? null,
                'subject_id'   => isset($attrs['subject_id']) ? (int) $attrs['subject_id'] : null,
                'old_values'   => $attrs['old_values'] ?? null,
                'new_values'   => $attrs['new_values'] ?? null,
                'ip_address'   => $attrs['ip_address'] ?? null,
                'created_at'   => now()->format('Y-m-d H:i:s'),
            ];
            $prev = (string) $state->last_hash;
            $hash = AuditChain::hash($prev, $row);

            $log = new AuditLog();
            $log->forceFill([
                'user_id'      => $row['actor_ref'],
                'action'       => $row['action'],
                'subject_type' => $row['subject_type'],
                'subject_id'   => $row['subject_id'],
                'old_values'   => $row['old_values'],
                'new_values'   => $row['new_values'],
                'ip_address'   => $row['ip_address'],
                'created_at'   => $row['created_at'],
                'chain_seq'    => $seq,
                'prev_hash'    => $prev,
                'entry_hash'   => $hash,
                'actor_ref'    => $row['actor_ref'],
            ])->save();

            DB::table('audit_chain_state')->where('id', 1)->update(['last_seq' => $seq, 'last_hash' => $hash, 'updated_at' => now()]);

            return $log;
        });
    }

    /** يختم كل الصفوف اللي لسه من غير chain_seq. @return int عدد الصفوف اللي اتختمت */
    public function sealPending(int $max = 0): int
    {
        $done = 0;
        while (true) {
            $limit = $max > 0 ? min(self::CHUNK, $max - $done) : self::CHUNK;
            if ($limit <= 0) {
                break;
            }
            $n = DB::transaction(function () use ($limit) {
                $state = $this->lockedState();
                $rows = DB::table('audit_logs')->whereNull('chain_seq')->orderBy('id')->limit($limit)->get();
                if ($rows->isEmpty()) {
                    return 0;
                }
                $seq = (int) $state->last_seq;
                $prev = (string) $state->last_hash;
                foreach ($rows as $r) {
                    $seq++;
                    $r->chain_seq = $seq;
                    $r->actor_ref = $r->user_id;
                    $hash = AuditChain::hash($prev, AuditChain::fromRaw($r));
                    DB::table('audit_logs')->where('id', $r->id)->update(['chain_seq' => $seq, 'prev_hash' => $prev, 'entry_hash' => $hash, 'actor_ref' => $r->user_id]);
                    $prev = $hash;
                }
                DB::table('audit_chain_state')->where('id', 1)->update(['last_seq' => $seq, 'last_hash' => $prev, 'updated_at' => now()]);

                return $rows->count();
            });
            $done += $n;
            if ($n < $limit) {
                break;
            }
        }

        return $done;
    }

    /** @return array<string,mixed> */
    public function verify(string $trigger = 'manual', ?int $userId = null, int $maxIssues = 50): array
    {
        $t0 = microtime(true);
        $issues = [];
        $total = 0;
        $firstBreak = null;
        $note = function (string $type, ?int $seq, string $detail) use (&$issues, &$total, &$firstBreak, $maxIssues): void {
            $total++;
            if ($seq !== null && ($firstBreak === null || $seq < $firstBreak)) {
                $firstBreak = $seq;
            }
            if (count($issues) < $maxIssues) {
                $issues[] = ['type' => $type, 'seq' => $seq, 'detail' => $detail];
            }
        };

        $state = DB::table('audit_chain_state')->where('id', 1)->first();
        $anchor = DB::table('audit_chain_anchors')->orderByDesc('up_to_seq')->first();
        $prevHash = AuditChain::genesis();
        $expect = 1;
        $startAfter = 0;
        if ($anchor) {
            if (!AuditChain::signatureValid((int) $anchor->up_to_seq, (string) $anchor->up_to_hash, (int) $anchor->pruned_count, (string) $anchor->signature)) {
                $note('anchor_forged', (int) $anchor->up_to_seq, 'The retention anchor signature is invalid.');
            }
            $prevHash = (string) $anchor->up_to_hash;
            $expect = (int) $anchor->up_to_seq + 1;
            $startAfter = (int) $anchor->up_to_seq;
        }

        $checked = 0;
        $last = $startAfter;
        while (true) {
            $rows = DB::table('audit_logs')->whereNotNull('chain_seq')->where('chain_seq', '>', $last)->orderBy('chain_seq')->limit(2000)->get();
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $r) {
                $seq = (int) $r->chain_seq;
                $checked++;
                $gap = false;
                if ($seq !== $expect) {
                    $gap = true;
                    $note('gap', $expect, $seq > $expect ? 'Entries ' . $expect . '–' . ($seq - 1) . ' are missing (deleted).' : 'Unexpected sequence number ' . $seq . '.');
                }
                if (!$gap && !hash_equals($prevHash, (string) $r->prev_hash)) {
                    $note('link', $seq, 'The entry is not linked to the previous entry.');
                }
                $calc = AuditChain::hash((string) $r->prev_hash, AuditChain::fromRaw($r));
                if (!hash_equals($calc, (string) $r->entry_hash)) {
                    $note('modified', $seq, 'The entry content no longer matches its hash (' . $r->action . ').');
                }
                $prevHash = (string) $r->entry_hash;
                $expect = $seq + 1;
                $last = $seq;
            }
        }

        // راس السلسلة: لو آخر صف مش هو اللي الـ state فاكره => اتمسح ذيل السلسلة أو اتغيّر.
        if ($state && ((int) $state->last_seq !== $expect - 1 || !hash_equals((string) $state->last_hash, $prevHash))) {
            $note('head_mismatch', (int) $state->last_seq, 'The newest entries do not match the recorded chain head (possible truncation).');
        }

        // نقاط التحقق الموقّعة.
        foreach (DB::table('audit_chain_checkpoints')->orderByDesc('id')->limit(500)->get() as $cp) {
            if (!AuditChain::signatureValid((int) $cp->seq, (string) $cp->entry_hash, (int) $cp->entries_count, (string) $cp->signature)) {
                $note('checkpoint_forged', (int) $cp->seq, 'A checkpoint signature is invalid.');
                continue;
            }
            if ((int) $cp->seq <= $startAfter) {
                continue; // اتمسح بشكل مشروع بسياسة الاحتفاظ
            }
            $row = DB::table('audit_logs')->where('chain_seq', $cp->seq)->first(['entry_hash']);
            if (!$row) {
                $note('checkpoint_missing', (int) $cp->seq, 'The entry sealed by a checkpoint no longer exists.');
            } elseif (!hash_equals((string) $cp->entry_hash, (string) $row->entry_hash)) {
                $note('checkpoint_mismatch', (int) $cp->seq, 'The entry differs from what a checkpoint recorded.');
            }
        }

        $unsealed = (int) DB::table('audit_logs')->whereNull('chain_seq')->count();
        $status = $total > 0 ? 'broken' : ($checked === 0 && $startAfter === 0 ? 'empty' : 'ok');
        $result = [
            'status'          => $status,
            'checked_rows'    => $checked,
            'unsealed_rows'   => $unsealed,
            'issues_total'    => $total,
            'first_break_seq' => $firstBreak,
            'issues'          => $issues,
            'duration_ms'     => (int) round((microtime(true) - $t0) * 1000),
            'trigger'         => $trigger,
        ];
        $id = DB::table('audit_chain_verifications')->insertGetId([
            'status' => $status, 'checked_rows' => $checked, 'unsealed_rows' => $unsealed, 'issues_total' => $total,
            'first_break_seq' => $firstBreak, 'issues' => json_encode($issues, JSON_UNESCAPED_UNICODE), 'duration_ms' => $result['duration_ms'],
            'trigger' => $trigger, 'run_by' => $userId, 'created_at' => now(),
        ]);
        $result['id'] = $id;

        if ($status === 'ok') {
            $this->checkpoint($trigger === 'scheduled' ? 'auto' : 'verify', $userId);
        } elseif ($status === 'broken') {
            $this->alertBroken($result);
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    public function checkpoint(string $kind = 'auto', ?int $userId = null): ?array
    {
        $state = DB::table('audit_chain_state')->where('id', 1)->first();
        if (!$state || (int) $state->last_seq === 0) {
            return null;
        }
        $last = DB::table('audit_chain_checkpoints')->orderByDesc('id')->first();
        if ($last && (int) $last->seq === (int) $state->last_seq && (string) $last->entry_hash === (string) $state->last_hash) {
            return (array) $last;
        }
        $count = (int) DB::table('audit_logs')->whereNotNull('chain_seq')->count();
        $sig = AuditChain::sign((int) $state->last_seq, (string) $state->last_hash, $count);
        $id = DB::table('audit_chain_checkpoints')->insertGetId([
            'seq' => $state->last_seq, 'entry_hash' => $state->last_hash, 'entries_count' => $count, 'signature' => $sig,
            'kind' => $kind, 'created_by' => $userId, 'created_at' => now(),
        ]);

        return (array) DB::table('audit_chain_checkpoints')->where('id', $id)->first();
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $state = DB::table('audit_chain_state')->where('id', 1)->first();
        $sealed = (int) DB::table('audit_logs')->whereNotNull('chain_seq')->count();
        $unsealed = (int) DB::table('audit_logs')->whereNull('chain_seq')->count();
        $firstAt = DB::table('audit_logs')->whereNotNull('chain_seq')->min('created_at');
        $lastV = DB::table('audit_chain_verifications')->orderByDesc('id')->first();
        $lastOk = DB::table('audit_chain_verifications')->where('status', 'ok')->orderByDesc('id')->first(['created_at']);
        $cp = DB::table('audit_chain_checkpoints')->orderByDesc('id')->first();
        $anchor = DB::table('audit_chain_anchors')->orderByDesc('up_to_seq')->first();

        return [
            'head_seq'          => (int) ($state->last_seq ?? 0),
            'head_hash'         => $state->last_hash ?? null,
            'sealed_rows'       => $sealed,
            'unsealed_rows'     => $unsealed,
            'oldest_sealed_at'  => $firstAt,
            'last_verification' => $lastV ? $this->verificationRow($lastV) : null,
            'last_ok_at'        => $lastOk->created_at ?? null,
            'last_checkpoint'   => $cp ? (array) $cp : null,
            'checkpoints_total' => (int) DB::table('audit_chain_checkpoints')->count(),
            'anchor'            => $anchor ? ['up_to_seq' => (int) $anchor->up_to_seq, 'pruned_count' => (int) $anchor->pruned_count, 'created_at' => $anchor->created_at] : null,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function verifications(int $limit = 20): array
    {
        return DB::table('audit_chain_verifications')->orderByDesc('id')->limit($limit)->get()->map(fn ($r) => $this->verificationRow($r))->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function checkpoints(int $limit = 20): array
    {
        return DB::table('audit_chain_checkpoints')->orderByDesc('id')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();
    }

    private function verificationRow(object $r): array
    {
        $a = (array) $r;
        $a['issues'] = json_decode((string) ($a['issues'] ?? '[]'), true) ?: [];

        return $a;
    }

    private function lockedState(): object
    {
        $state = DB::table('audit_chain_state')->where('id', 1)->lockForUpdate()->first();
        if (!$state) {
            DB::table('audit_chain_state')->insertOrIgnore(['id' => 1, 'last_seq' => 0, 'last_hash' => AuditChain::genesis()]);
            $state = DB::table('audit_chain_state')->where('id', 1)->lockForUpdate()->first();
        }

        return $state;
    }

    /** إشعار لكل security_admin/admin لو السلسلة اتكسرت. */
    private function alertBroken(array $result): void
    {
        try {
            $ids = DB::table('users as u')->join('user_roles as ur', 'ur.user_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->whereIn('r.slug', ['security_admin', 'admin'])->whereNull('u.deleted_at')->distinct()->pluck('u.id');
            $notifier = app(NotificationService::class);
            foreach ($ids as $id) {
                $notifier->notify((int) $id, 'security_alert', 'Audit log integrity check failed',
                    ($result['issues_total'] ?? 0) . ' integrity issue(s) found in the audit log chain (first at #' . ($result['first_break_seq'] ?? '?') . ').',
                    '/security/compliance?tab=integrity', 'high');
            }
        } catch (\Throwable $e) {
            Log::error('Audit chain alert failed', ['error' => $e->getMessage()]);
        }
    }
}
