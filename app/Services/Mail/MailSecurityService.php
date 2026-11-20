<?php

namespace App\Services\Mail;

use App\Models\Mail\MailAccount;
use App\Support\IpMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UIPE Mail — المرحلة 7: حدود الإرسال، كشف النشاط المشبوه، الإيقاف التلقائي، IP rules، Suppression list، Legal hold.
 * الخصوصية: الأحداث بتحمل أرقام وعناوين بس — مفيش موضوع/نص رسائل.
 */
class MailSecurityService
{
    public function __construct(private MailAuditService $audit)
    {
    }

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    /* ------------------------------------------------------------------ events */

    /** يسجّل حدث أمني. $dedupe = ما يتكررش لنفس (النوع + الصندوق/الـ IP) لو فيه واحد مفتوح خلال الساعة. */
    public function event(string $type, string $severity, array $ctx = [], bool $dedupe = true): ?int
    {
        $q = DB::table('mail_security_events')->where('type', $type)->where('status', 'open')->where('created_at', '>', now()->subHour());
        if (!empty($ctx['account_id'])) {
            $q->where('account_id', $ctx['account_id']);
        } elseif (!empty($ctx['ip'])) {
            $q->where('ip_address', $ctx['ip']);
        }
        if ($dedupe && (!empty($ctx['account_id']) || !empty($ctx['ip'])) && $q->exists()) {
            return null;
        }
        return (int) DB::table('mail_security_events')->insertGetId([
            'university_id' => $ctx['university_id'] ?? null, 'tenant_id' => $ctx['tenant_id'] ?? null, 'account_id' => $ctx['account_id'] ?? null,
            'user_id' => $ctx['user_id'] ?? null, 'type' => $type, 'severity' => $severity, 'status' => 'open', 'ip_address' => $ctx['ip'] ?? null,
            'details' => isset($ctx['details']) ? json_encode($ctx['details'], JSON_UNESCAPED_UNICODE) : null, 'created_at' => now(),
        ]);
    }

    public function events(?int $universityId, array $f, int $page = 1, int $perPage = 25): array
    {
        $q = DB::table('mail_security_events as e')->leftJoin('mail_accounts as a', 'a.id', '=', 'e.account_id');
        if ($universityId !== null) {
            $q->where('e.university_id', $universityId);
        }
        foreach (['status', 'type', 'severity'] as $k) {
            if (!empty($f[$k])) {
                $q->where("e.{$k}", (string) $f[$k]);
            }
        }
        $perPage = min(100, max(5, $perPage));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('e.id')->forPage(max(1, $page), $perPage)->get(['e.*', 'a.address as account_address']);
        return ['items' => $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'university_id' => $r->university_id ? (int) $r->university_id : null, 'type' => $r->type, 'severity' => $r->severity, 'status' => $r->status,
            'account' => $r->account_id ? ['id' => (int) $r->account_id, 'address' => $r->account_address] : null, 'ip' => $r->ip_address,
            'details' => $r->details ? json_decode((string) $r->details, true) : null, 'resolution_note' => $r->resolution_note,
            'created_at' => Carbon::parse($r->created_at, 'UTC')->toIso8601String(), 'resolved_at' => $r->resolved_at ? Carbon::parse($r->resolved_at, 'UTC')->toIso8601String() : null,
        ])->all(), 'total' => $total, 'page' => max(1, $page), 'per_page' => $perPage];
    }

    public function resolveEvent(?int $universityId, int $id, string $status, ?string $note, int $by): array
    {
        if (!in_array($status, ['resolved', 'ignored'], true)) {
            $this->fail('Invalid status.');
        }
        $q = DB::table('mail_security_events')->where('id', $id);
        if ($universityId !== null) {
            $q->where('university_id', $universityId);
        }
        $ev = $q->first();
        if (!$ev) {
            $this->fail('Event not found.', 404);
        }
        DB::table('mail_security_events')->where('id', $id)->update(['status' => $status, 'resolved_by' => $by, 'resolution_note' => $note !== null ? mb_substr($note, 0, 250) : null, 'resolved_at' => now()]);
        return ['id' => $id, 'status' => $status];
    }

    public function overview(?int $universityId): array
    {
        $ev = fn () => DB::table('mail_security_events')->when($universityId !== null, fn ($q) => $q->where('university_id', $universityId));
        $open = $ev()->where('status', 'open')->selectRaw('severity, COUNT(*) c')->groupBy('severity')->pluck('c', 'severity');
        $day = now()->subDay();
        $byType = $ev()->where('created_at', '>', now()->subDays(7))->selectRaw('type, COUNT(*) c')->groupBy('type')->pluck('c', 'type');
        return [
            'open_events' => ['high' => (int) ($open['high'] ?? 0), 'medium' => (int) ($open['medium'] ?? 0), 'low' => (int) ($open['low'] ?? 0)],
            'events_7d_by_type' => $byType->map(fn ($c) => (int) $c)->all(),
            'ip_rules' => (int) DB::table('mail_ip_rules')->where('university_id', $universityId ?? 0)->count(),
            'platform_ip_rules' => $universityId === null ? 0 : (int) DB::table('mail_ip_rules')->where('university_id', 0)->count(),
            'suppressions' => (int) DB::table('mail_suppressions')->when($universityId !== null, fn ($q) => $q->where('university_id', $universityId))->count(),
            'bounces_24h' => (int) DB::table('mail_bounces')->when($universityId !== null, fn ($q) => $q->where('university_id', $universityId))->where('created_at', '>', $day)->count(),
            'legal_holds' => (int) DB::table('mail_accounts')->where('legal_hold', 1)->when($universityId !== null, fn ($q) => $q->where('university_id', $universityId))->count(),
            'auto_suspended' => (int) DB::table('mail_accounts')->where('suspended_by', 'security')->when($universityId !== null, fn ($q) => $q->where('university_id', $universityId))->count(),
        ];
    }

    /* ------------------------------------------------------------------ send limits + auto-suspend */

    /** @return array{per_hour:int,recipients:int,external:int} */
    public function limitsFor(?object $tenant): array
    {
        $c = (array) config('mailbox.security', []);
        return [
            'per_hour' => (int) ($tenant->sec_max_per_hour ?? 0) ?: (int) ($c['max_per_hour'] ?? 200),
            'recipients' => (int) ($tenant->sec_max_recipients_per_hour ?? 0) ?: (int) ($c['max_recipients_per_hour'] ?? 1000),
            'external' => (int) ($tenant->sec_max_external_per_hour ?? 0) ?: (int) ($c['max_external_per_hour'] ?? 100),
        ];
    }

    private function tenantOf(MailAccount $a): ?object
    {
        return $a->tenant_id ? DB::table('mail_tenants')->where('id', $a->tenant_id)->first() : null;
    }

    /** يرمي 429 لو الإرسال ده يعدّي الحد الساعي. بيتنادى قبل الإرسال الفعلي وعند تسليم المجدول. */
    public function assertMaySend(MailAccount $a, int $recipients, int $external): void
    {
        $t = $this->tenantOf($a);
        $lim = $this->limitsFor($t);
        $since = now()->subHour();
        $sent = (int) DB::table('mail_messages')->where('sender_account_id', $a->id)->where('is_draft', 0)->where('is_auto', 0)->where('sent_at', '>', $since)->count();
        $row = DB::table('mail_recipients as r')->join('mail_messages as m', 'm.id', '=', 'r.message_id')->where('m.sender_account_id', $a->id)->where('m.is_draft', 0)->where('m.is_auto', 0)->where('m.sent_at', '>', $since)
            ->selectRaw('COUNT(*) total, SUM(CASE WHEN r.account_id IS NULL THEN 1 ELSE 0 END) ext')->first();
        $rc = (int) ($row->total ?? 0) + $recipients;
        $ext = (int) ($row->ext ?? 0) + $external;
        $what = null;
        if ($sent + 1 > $lim['per_hour']) {
            $what = 'messages';
        } elseif ($rc > $lim['recipients']) {
            $what = 'recipients';
        } elseif ($ext > $lim['external']) {
            $what = 'external recipients';
        }
        if (!$what) {
            return;
        }
        $this->event('send_limit', 'medium', ['university_id' => $a->university_id, 'tenant_id' => $a->tenant_id, 'account_id' => $a->id, 'user_id' => $a->user_id,
            'details' => ['limit' => $what, 'sent_1h' => $sent, 'recipients_1h' => $rc, 'external_1h' => $ext, 'limits' => $lim]]);
        // عدّاد مخالفات 24 ساعة في الكاش (الأحداث نفسها بتتدمج: حدث مفتوح واحد/ساعة، فمينفعش نعدّ منها).
        $key = "mail:sec:viol:{$a->id}";
        Cache::add($key, 0, now()->addDay());
        $violations = (int) Cache::increment($key);
        if ($t && $t->sec_auto_suspend && $violations >= (int) config('mailbox.security.suspend_after_violations', 5)) {
            $this->autoSuspend($a, 'Too many sending-limit violations');
        }
        $this->fail("Hourly sending limit reached ({$what}). Try again later. / تم الوصول للحد الأقصى للإرسال في الساعة. حاول لاحقًا.", 429);
    }

    public function autoSuspend(MailAccount $a, string $reason): void
    {
        if ($a->status !== 'active') {
            return;
        }
        DB::table('mail_accounts')->where('id', $a->id)->update(['status' => 'suspended', 'suspended_by' => 'security', 'suspended_reason' => mb_substr($reason, 0, 190), 'updated_at' => now()]);
        $this->event('auto_suspended', 'high', ['university_id' => $a->university_id, 'tenant_id' => $a->tenant_id, 'account_id' => $a->id, 'user_id' => $a->user_id, 'details' => ['reason' => $reason]], false);
        $this->audit->record('mail.account.auto_suspended', $reason, ['university_id' => $a->university_id, 'tenant_id' => $a->tenant_id, 'account_id' => $a->id, 'target_type' => 'account', 'target_id' => $a->id]);
    }

    /** المراقبة الدورية: brute-force على اللوجين + قفزة bounces. بيتنادى من mail:security-scan. */
    public function scan(): array
    {
        $c = (array) config('mailbox.security', []);
        $found = ['bruteforce' => 0, 'bounce_spike' => 0];
        if (Schema::hasTable('login_events')) {
            $win = (int) ($c['bruteforce_window_min'] ?? 15);
            $min = (int) ($c['bruteforce_failures'] ?? 10);
            $rows = DB::table('login_events')->where('success', 0)->where('created_at', '>', now()->subMinutes($win))->whereNotNull('ip_address')
                ->selectRaw('ip_address, COUNT(*) c, COUNT(DISTINCT identifier) ids')->groupBy('ip_address')->havingRaw('COUNT(*) >= ?', [$min])->get();
            foreach ($rows as $r) {
                if ($this->event('login_bruteforce', ((int) $r->c >= $min * 3) ? 'high' : 'medium', ['ip' => $r->ip_address, 'details' => ['failures' => (int) $r->c, 'distinct_identifiers' => (int) $r->ids, 'window_min' => $win]])) {
                    $found['bruteforce']++;
                }
            }
        }
        $spike = (int) ($c['bounce_spike_24h'] ?? 10);
        $rows = DB::table('mail_bounces')->whereNotNull('account_id')->whereIn('type', ['hard', 'complaint', 'blocked'])->where('created_at', '>', now()->subDay())
            ->selectRaw('account_id, COUNT(*) c')->groupBy('account_id')->havingRaw('COUNT(*) >= ?', [$spike])->get();
        foreach ($rows as $r) {
            $a = MailAccount::find($r->account_id);
            if (!$a) {
                continue;
            }
            if ($this->event('bounce_spike', 'high', ['university_id' => $a->university_id, 'tenant_id' => $a->tenant_id, 'account_id' => $a->id, 'user_id' => $a->user_id, 'details' => ['bounces_24h' => (int) $r->c]])) {
                $found['bounce_spike']++;
                $t = $this->tenantOf($a);
                if ($t && $t->sec_auto_suspend) {
                    $this->autoSuspend($a, 'Bounce/complaint spike');
                }
            }
        }
        return $found;
    }

    /** يفك إيقاف الأمان لصندوق (الأدمن بيراجع الأول). */
    public function reinstate(?int $universityId, int $accountId): array
    {
        $a = MailAccount::where('id', $accountId)->when($universityId !== null, fn ($q) => $q->where('university_id', $universityId))->first();
        if (!$a) {
            $this->fail('Mailbox not found.', 404);
        }
        if ($a->suspended_by !== 'security') {
            $this->fail('This mailbox was not suspended by security monitoring.');
        }
        DB::table('mail_accounts')->where('id', $a->id)->update(['status' => 'active', 'suspended_by' => null, 'suspended_reason' => null, 'updated_at' => now()]);
        return ['id' => (int) $a->id, 'status' => 'active'];
    }

    /* ------------------------------------------------------------------ IP rules */

    public function rules(?int $universityId): array
    {
        return DB::table('mail_ip_rules')->where('university_id', $universityId ?? 0)->orderByDesc('id')->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'cidr' => $r->cidr, 'action' => $r->action, 'note' => $r->note,
            'expires_at' => $r->expires_at ? Carbon::parse($r->expires_at, 'UTC')->toIso8601String() : null,
            'created_at' => Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
        ])->all();
    }

    /** $universityId=null → قاعدة على مستوى المنصة (block بس). $actorIp لمنع الأدمن من حظر نفسه. */
    public function addRule(?int $universityId, string $cidr, string $action, ?string $note, ?string $expiresAt, int $by, ?string $actorIp): array
    {
        $canon = IpMatcher::canonical($cidr);
        if (!$canon) {
            $this->fail('Invalid IP address or CIDR range.');
        }
        if (!in_array($action, ['block', 'allow'], true)) {
            $this->fail('Invalid action.');
        }
        if ($universityId === null && $action === 'allow') {
            $this->fail('Platform-wide rules can only block.');
        }
        $uid = $universityId ?? 0;
        if ($actorIp && $action === 'block' && IpMatcher::matches($actorIp, $canon)) {
            $this->fail('This rule would block your own IP address.');
        }
        if ($action === 'allow' && $actorIp && !DB::table('mail_ip_rules')->where('university_id', $uid)->where('action', 'allow')->exists() && !IpMatcher::matches($actorIp, $canon)) {
            $this->fail('The first allow rule must include your own IP address (otherwise only listed addresses could use the mailbox, including you).');
        }
        $exp = null;
        if ($expiresAt) {
            $exp = strtotime($expiresAt) ? Carbon::parse($expiresAt)->utc() : null;
            if (!$exp || $exp->isPast()) {
                $this->fail('Expiry must be in the future.');
            }
        }
        if (DB::table('mail_ip_rules')->where('university_id', $uid)->where('cidr', $canon)->exists()) {
            $this->fail('This rule already exists.', 409);
        }
        if (DB::table('mail_ip_rules')->where('university_id', $uid)->count() >= 200) {
            $this->fail('Too many rules (max 200).');
        }
        $id = DB::table('mail_ip_rules')->insertGetId(['university_id' => $uid, 'cidr' => $canon, 'action' => $action, 'note' => $note !== null ? mb_substr($note, 0, 190) : null, 'created_by' => $by, 'expires_at' => $exp, 'created_at' => now()]);
        Cache::forget('mail:iprules');
        return ['id' => (int) $id, 'cidr' => $canon, 'action' => $action];
    }

    public function removeRule(?int $universityId, int $id, ?string $actorIp = null): void
    {
        $r = DB::table('mail_ip_rules')->where('id', $id)->where('university_id', $universityId ?? 0)->first();
        if (!$r) {
            $this->fail('Rule not found.', 404);
        }
        // حذف آخر allow = رجوع لوضع "مفتوح" (آمن)، فمفيش خطر قفل.
        DB::table('mail_ip_rules')->where('id', $id)->delete();
        Cache::forget('mail:iprules');
    }

    /** كل القواعد السارية (كاش 60 ثانية؛ add/remove بيمسحوه). */
    private function cachedRules(): array
    {
        return Cache::remember('mail:iprules', 60, fn () => DB::table('mail_ip_rules')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->get(['university_id', 'cidr', 'action'])->all());
    }

    /** فيه أي قاعدة IP أصلًا؟ (الميدلوير بيستخدمها عشان ميعملش استعلامات لو مفيش قواعد.) */
    public function hasRules(): bool
    {
        return count($this->cachedRules()) > 0;
    }

    /** يمسح القواعد المنتهية. بيتنادى من mail:security-scan. */
    public function pruneExpiredRules(): int
    {
        $n = DB::table('mail_ip_rules')->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
        if ($n) {
            Cache::forget('mail:iprules');
        }
        return $n;
    }

    /** @return string|null سبب المنع، أو null لو مسموح. $universityId = جامعة الصندوق المستخدَم (null = صندوق شخصي: قواعد المنصة بس). */
    public function ipBlockReason(string $ip, ?int $universityId): ?string
    {
        if ($ip === '') {
            return null;
        }
        $hasAllow = false; $allowed = false;
        foreach ($this->cachedRules() as $r) {
            $uni = (int) $r->university_id;
            if ($uni !== 0 && $uni !== (int) $universityId) {
                continue;
            }
            $m = IpMatcher::matches($ip, (string) $r->cidr);
            if ($r->action === 'block' && $m) {
                return 'blocked';
            }
            if ($r->action === 'allow' && $uni !== 0) {
                $hasAllow = true;
                $allowed = $allowed || $m;
            }
        }
        return $hasAllow && !$allowed ? 'not_allowed' : null;
    }

    /* ------------------------------------------------------------------ suppression list */

    private function suppressionScope(MailAccount $a): int
    {
        return $a->tenant_id && $a->university_id ? (int) $a->university_id : 0;
    }

    /** يرجّع العناوين الموقوف الإرسال ليها (من اللي اتبعتت). */
    public function suppressedAmong(MailAccount $sender, array $addresses): array
    {
        $addresses = array_values(array_unique(array_map('strtolower', $addresses)));
        if (!$addresses) {
            return [];
        }
        return DB::table('mail_suppressions')->whereIn('university_id', array_unique([0, $this->suppressionScope($sender)]))->whereIn('address', $addresses)->pluck('address')->map(fn ($x) => strtolower((string) $x))->all();
    }

    public function suppressions(?int $universityId, ?string $q, int $page = 1, int $perPage = 30): array
    {
        $b = DB::table('mail_suppressions')->where('university_id', $universityId ?? 0);
        if ($q) {
            $b->where('address', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], strtolower(trim($q))) . '%');
        }
        $perPage = min(100, max(5, $perPage));
        $total = (clone $b)->count();
        return ['items' => $b->orderByDesc('id')->forPage(max(1, $page), $perPage)->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'address' => $r->address, 'reason' => $r->reason, 'note' => $r->note, 'created_at' => Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
        ])->all(), 'total' => $total, 'page' => max(1, $page), 'per_page' => $perPage];
    }

    public function addSuppression(?int $universityId, string $address, string $reason = 'manual', ?string $note = null, ?int $by = null): void
    {
        $address = strtolower(trim($address));
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $this->fail('Invalid email address.');
        }
        DB::table('mail_suppressions')->insertOrIgnore(['university_id' => $universityId ?? 0, 'address' => $address, 'reason' => $reason, 'note' => $note !== null ? mb_substr($note, 0, 190) : null, 'created_by' => $by, 'created_at' => now()]);
    }

    public function removeSuppression(?int $universityId, int $id): void
    {
        if (!DB::table('mail_suppressions')->where('id', $id)->where('university_id', $universityId ?? 0)->delete()) {
            $this->fail('Not found.', 404);
        }
    }

    /* ------------------------------------------------------------------ policy (limits + retention + auto-suspend) */

    public const POLICY_FIELDS = ['retention_trash_days', 'retention_spam_days', 'retention_sent_days', 'retention_all_days',
        'sec_max_per_hour', 'sec_max_recipients_per_hour', 'sec_max_external_per_hour'];

    public function policy(object $tenant): array
    {
        $out = ['limits_effective' => $this->limitsFor($tenant), 'sec_auto_suspend' => (bool) ($tenant->sec_auto_suspend ?? false),
            'default_trash_days' => (int) config('mailbox.trash_days', 30)];
        foreach (self::POLICY_FIELDS as $f) {
            $out[$f] = isset($tenant->{$f}) && $tenant->{$f} !== null ? (int) $tenant->{$f} : null;
        }
        return $out;
    }

    /** $in: أي حقل من POLICY_FIELDS (null = الافتراضي) + sec_auto_suspend. @return array{0:array,1:array} [policy الجديدة, التغييرات old→new] */
    public function updatePolicy(int $tenantId, array $in): array
    {
        $t = DB::table('mail_tenants')->where('id', $tenantId)->first();
        if (!$t) {
            $this->fail('Mail tenant not found.', 404);
        }
        $min = ['retention_trash_days' => 1, 'retention_spam_days' => 1, 'retention_sent_days' => 7, 'retention_all_days' => 30,
            'sec_max_per_hour' => 1, 'sec_max_recipients_per_hour' => 1, 'sec_max_external_per_hour' => 1];
        $max = ['retention_trash_days' => 3650, 'retention_spam_days' => 3650, 'retention_sent_days' => 3650, 'retention_all_days' => 3650,
            'sec_max_per_hour' => 5000, 'sec_max_recipients_per_hour' => 50000, 'sec_max_external_per_hour' => 10000];
        $upd = []; $changes = [];
        foreach (self::POLICY_FIELDS as $f) {
            if (!array_key_exists($f, $in)) {
                continue;
            }
            $v = $in[$f];
            if ($v === null || $v === '') {
                $v = null;
            } else {
                $v = (int) $v;
                if ($v < $min[$f] || $v > $max[$f]) {
                    $this->fail("{$f} must be between {$min[$f]} and {$max[$f]}.");
                }
            }
            $old = $t->{$f} !== null ? (int) $t->{$f} : null;
            if ($old !== $v) {
                $upd[$f] = $v;
                $changes[$f] = [$old, $v];
            }
        }
        if (array_key_exists('sec_auto_suspend', $in)) {
            $v = filter_var($in['sec_auto_suspend'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            if ((int) $t->sec_auto_suspend !== $v) {
                $upd['sec_auto_suspend'] = $v;
                $changes['sec_auto_suspend'] = [(bool) $t->sec_auto_suspend, (bool) $v];
            }
        }
        if ($upd) {
            DB::table('mail_tenants')->where('id', $tenantId)->update($upd + ['updated_at' => now()]);
        }
        return [$this->policy(DB::table('mail_tenants')->where('id', $tenantId)->first()), $changes];
    }

    /** صناديق تحت Legal hold أو موقوفة بواسطة الأمان (للأدمن عشان يراجعها ويفك). */
    public function flaggedAccounts(?int $universityId): array
    {
        $rows = DB::table('mail_accounts')->where(fn ($q) => $q->where('legal_hold', 1)->orWhere('suspended_by', 'security'))
            ->when($universityId !== null, fn ($q) => $q->where('university_id', $universityId))->orderByDesc('updated_at')->limit(200)
            ->get(['id', 'address', 'display_name', 'university_id', 'status', 'suspended_by', 'suspended_reason', 'legal_hold', 'legal_hold_reason']);
        return $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'address' => $r->address, 'display_name' => $r->display_name, 'university_id' => $r->university_id ? (int) $r->university_id : null,
            'status' => $r->status, 'security_suspended' => $r->suspended_by === 'security', 'suspended_reason' => $r->suspended_by === 'security' ? $r->suspended_reason : null,
            'legal_hold' => (bool) $r->legal_hold, 'legal_hold_reason' => $r->legal_hold ? $r->legal_hold_reason : null,
        ])->all();
    }

    /* ------------------------------------------------------------------ legal hold */

    public function setLegalHold(?int $universityId, int $accountId, bool $on, ?string $reason): array
    {
        $a = MailAccount::where('id', $accountId)->when($universityId !== null, fn ($q) => $q->where('university_id', $universityId))->first();
        if (!$a) {
            $this->fail('Mailbox not found.', 404);
        }
        DB::table('mail_accounts')->where('id', $a->id)->update(['legal_hold' => $on ? 1 : 0, 'legal_hold_reason' => $on && $reason ? mb_substr($reason, 0, 190) : null, 'updated_at' => now()]);
        return ['id' => (int) $a->id, 'address' => $a->address, 'legal_hold' => $on];
    }
}
