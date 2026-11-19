<?php

namespace App\Services\Mail;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * UIPE Mail — المرحلة 5: تعليق الصندوق المؤسسي لما العضوية تنتهي، واستئنافه لو رجعت.
 * أسباب الانتهاء (بالأولوية): membership_expired · account_suspended · left_university · graduated (بعد graduate_grace_days) · staff_inactive.
 * بنعلّق بـ suspended_by='membership' وبنستأنف الصناديق دي بس — اللي الأدمن وقّفها يدويًا مبنلمسهاش.
 * لو tenant.suspend_on_expiry=false بنستأنف اللي اتعلّق تلقائيًا ومنعلّقش جديد.
 */
class MailMembershipService
{
    public const REASONS = ['membership_expired', 'account_suspended', 'left_university', 'graduated', 'staff_inactive'];

    private function tenants(?int $universityId)
    {
        return DB::table('mail_tenants')->when($universityId, fn ($q) => $q->where('university_id', $universityId))->get();
    }

    /** @return array<int,string> accountId => reason */
    private function ended(object $t): array
    {
        $out = [];
        $add = function ($ids, string $reason) use (&$out) {
            foreach ($ids as $id) {
                $out[(int) $id] ??= $reason;
            }
        };
        $base = fn () => DB::table('mail_accounts as a')->where('a.tenant_id', $t->id)->where('a.kind', 'institutional');

        $add($base()->whereNotNull('a.membership_expires_at')->where('a.membership_expires_at', '<=', now())->pluck('a.id'), 'membership_expired');

        $add($base()->join('users as u', 'u.id', '=', 'a.user_id')
            ->where(fn ($w) => $w->whereIn('u.status', ['suspended', 'banned'])->orWhereNotNull('u.deleted_at'))->pluck('a.id'), 'account_suspended');

        $add($base()->where('a.member_type', 'student')->leftJoin('students as s', 's.id', '=', 'a.member_id')
            ->where(fn ($w) => $w->whereNull('s.id')->orWhereNull('s.university_id')->orWhereColumn('s.university_id', '!=', 'a.university_id'))->pluck('a.id'), 'left_university');

        $cutoff = now()->subDays((int) ($t->graduate_grace_days ?? 180))->toDateString();
        $add($base()->where('a.member_type', 'student')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('graduation_records as g')->whereColumn('g.student_id', 'a.member_id')
                ->where('g.status', 'graduated')->where('g.graduation_date', '<=', $cutoff))->pluck('a.id'), 'graduated');

        $add($base()->where('a.member_type', 'staff')->leftJoin('academic_staff as st', 'st.id', '=', 'a.member_id')
            ->where(fn ($w) => $w->whereNull('st.id')->orWhere('st.status', '!=', 'active')->orWhereColumn('st.university_id', '!=', 'a.university_id'))->pluck('a.id'), 'staff_inactive');

        return $out;
    }

    /**
     * @return array{suspended:int,resumed:int,items:array<int,array<string,mixed>>}
     * $dryRun=true → بيحسب بس.
     */
    public function sweep(?int $universityId = null, bool $dryRun = false): array
    {
        $res = ['suspended' => 0, 'resumed' => 0, 'items' => []];
        foreach ($this->tenants($universityId) as $t) {
            $policyOn = (bool) ($t->suspend_on_expiry ?? true);
            $ended = $policyOn ? $this->ended($t) : [];

            $toSuspend = $ended ? DB::table('mail_accounts')->where('tenant_id', $t->id)->where('status', 'active')->whereIn('id', array_keys($ended))->get(['id', 'address', 'member_type']) : collect();
            $toResume = DB::table('mail_accounts')->where('tenant_id', $t->id)->where('status', 'suspended')->where('suspended_by', 'membership')
                ->when($ended, fn ($q) => $q->whereNotIn('id', array_keys($ended)))->get(['id', 'address', 'member_type']);

            foreach ($toSuspend as $a) {
                if (count($res['items']) < 200) {
                    $res['items'][] = ['action' => 'suspend', 'id' => (int) $a->id, 'address' => $a->address, 'member_type' => $a->member_type, 'reason' => $ended[(int) $a->id]];
                }
            }
            foreach ($toResume as $a) {
                if (count($res['items']) < 200) {
                    $res['items'][] = ['action' => 'resume', 'id' => (int) $a->id, 'address' => $a->address, 'member_type' => $a->member_type, 'reason' => null];
                }
            }
            $res['suspended'] += $toSuspend->count();
            $res['resumed'] += $toResume->count();
            if ($dryRun) {
                continue;
            }

            foreach ($toSuspend->groupBy(fn ($a) => $ended[(int) $a->id]) as $reason => $group) {
                foreach ($group->pluck('id')->chunk(500) as $chunk) {
                    DB::table('mail_accounts')->whereIn('id', $chunk->all())->update(['status' => 'suspended', 'suspended_reason' => $reason, 'suspended_by' => 'membership', 'updated_at' => now()]);
                }
            }
            foreach ($toResume->pluck('id')->chunk(500) as $chunk) {
                DB::table('mail_accounts')->whereIn('id', $chunk->all())->update(['status' => 'active', 'suspended_reason' => null, 'suspended_by' => null, 'updated_at' => now()]);
            }
            if ($toSuspend->count() || $toResume->count()) {
                DB::table('provisioning_runs')->insert(['tenant_id' => $t->id, 'university_id' => $t->university_id, 'kind' => 'sweep', 'total' => $toSuspend->count() + $toResume->count(),
                    'created' => $toSuspend->count(), 'failed' => 0, 'created_at' => now()]);
            }
        }
        return $res;
    }

    /** تاريخ انتهاء عضوية صندوق (null = بدون انتهاء). ISO8601 أو Y-m-d. */
    public function setExpiry(int $universityId, int $accountId, ?string $expiresAt): array
    {
        $t = DB::table('mail_tenants')->where('university_id', $universityId)->first();
        $a = $t ? DB::table('mail_accounts')->where('id', $accountId)->where('tenant_id', $t->id)->where('kind', 'institutional')->first() : null;
        if (!$a) {
            throw new \DomainException('Mailbox not found.', 404);
        }
        $val = null;
        if ($expiresAt !== null && trim($expiresAt) !== '') {
            try {
                $val = Carbon::parse($expiresAt)->utc()->toDateTimeString();
            } catch (\Throwable) {
                throw new \DomainException('Invalid date.', 422);
            }
        }
        DB::table('mail_accounts')->where('id', $accountId)->update(['membership_expires_at' => $val, 'updated_at' => now()]);
        return ['id' => $accountId, 'membership_expires_at' => $val ? Carbon::parse($val, 'UTC')->toIso8601String() : null];
    }
}
