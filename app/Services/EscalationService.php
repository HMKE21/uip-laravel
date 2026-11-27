<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * بند 7 — قنوات التصعيد: لما تنبيه أو حادثة بخطورة كفاية تتولّد، بنبعت على القنوات (إيميل / Slack / Telegram / Webhook)
 * والمناوب الحالي، ولو محدش استلمها (ما اتغيّرتش حالتها من open) خلال مهلة القاعدة بنصعّد للمستوى التاني.
 * وفيه كمان ملخصات أمنية مجدولة بتتبعت تلقائي. كل الإرسال بيتم بعد الرد على المستخدم (terminating) وأي فشل بيتسجّل بس.
 */
class EscalationService
{
    public const SOURCES = ['any', 'alert', 'incident'];
    public const SECTIONS = ['alerts', 'incidents', 'vulnerabilities', 'audit'];

    public function __construct(
        private EscalationNotifier $notifier,
        private AuditLogService $auditLog
    ) {
    }

    // ───────────────────────── triggering ─────────────────────────

    /**
     * نقطة الدخول من SecurityAlertService / SecurityIncidentRepository. بتأجّل الإرسال لبعد الرد، ومبتكسرش حاجة لو فشلت.
     *
     * @param array<string,string> $fields
     */
    public static function fire(string $sourceType, int $sourceId, string $severity, string $category, string $title, ?string $body = null, array $fields = []): void
    {
        try {
            if (EscalationNotifier::rank($severity) < 1) {
                return; // info: مش تصعيد أبدًا
            }
            app()->terminating(function () use ($sourceType, $sourceId, $severity, $category, $title, $body, $fields) {
                try {
                    app(self::class)->dispatchEvent($sourceType, $sourceId, $severity, $category, $title, $body, $fields);
                } catch (\Throwable $e) {
                    Log::warning('Escalation dispatch failed: ' . $e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Escalation could not be scheduled: ' . $e->getMessage());
        }
    }

    /** @param array<string,string> $fields */
    public function dispatchEvent(string $sourceType, int $sourceId, string $severity, string $category, string $title, ?string $body, array $fields = []): int
    {
        if (!Schema::hasTable('escalation_policies')) {
            return 0;
        }
        $sent = [];
        $runs = 0;
        $msg = $this->message($sourceType, $sourceId, $severity, $category, $title, $body, $fields);

        foreach (DB::table('escalation_policies')->where('is_active', 1)->orderBy('id')->get() as $p) {
            if (!$this->matches($p, $sourceType, $severity, $category)) {
                continue;
            }
            $ins = DB::table('escalation_runs')->insertOrIgnore([
                'policy_id' => $p->id, 'source_type' => $sourceType, 'source_id' => $sourceId, 'severity' => $severity,
                'title' => mb_substr($title, 0, 200), 'level' => 1, 'status' => 'pending', 'notified_at' => now(),
                'next_check_at' => $this->level2Due($p), 'created_at' => now(),
            ]);
            if (!$ins) {
                continue; // اتصعّد قبل كده لنفس القاعدة
            }
            $runId = (int) DB::table('escalation_runs')->where('policy_id', $p->id)->where('source_type', $sourceType)->where('source_id', $sourceId)->value('id');
            $this->notify($p, $runId, 1, $msg, (array) json_decode((string) $p->channel_ids, true), (bool) $p->notify_oncall, false, $sent);
            $runs++;
        }

        return $runs;
    }

    private function matches(object $p, string $sourceType, string $severity, string $category): bool
    {
        if ($p->source !== 'any' && $p->source !== $sourceType) {
            return false;
        }
        if (EscalationNotifier::rank($severity) < EscalationNotifier::rank($p->min_severity)) {
            return false;
        }
        $types = $p->types ? (array) json_decode((string) $p->types, true) : [];

        return !$types || in_array($category, $types, true);
    }

    private function level2Due(object $p): ?string
    {
        $m = (int) $p->ack_timeout_minutes;
        $has = $p->level2_channel_ids ? (array) json_decode((string) $p->level2_channel_ids, true) : [];

        return $m > 0 && ($has || $p->level2_notify_admins) ? now()->addMinutes($m)->toDateTimeString() : null;
    }

    /** @param array<string,string> $fields */
    private function message(string $sourceType, int $sourceId, string $severity, string $category, string $title, ?string $body, array $fields): array
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/');
        $url = $sourceType === 'incident' ? "{$base}/security/incidents/{$sourceId}" : "{$base}/security/alerts";
        $lines = $body ? [mb_substr($body, 0, 800)] : [];

        return [
            'title' => mb_substr($title, 0, 200), 'lines' => $lines, 'severity' => $severity, 'url' => $url, 'kind' => $sourceType,
            'fields' => array_merge(['Type' => $category, ucfirst($sourceType) => '#' . $sourceId], $fields),
        ];
    }

    /**
     * @param int[] $channelIds
     * @param array<int|string> $sent القنوات/الأشخاص اللي اتبعتلهم في نفس الدفعة (منبعتش مرتين لو قاعدتين اتطبقوا)
     */
    private function notify(object $p, int $runId, int $level, array $msg, array $channelIds, bool $oncall, bool $admins, array &$sent): void
    {
        $sev = $msg['severity'];
        if ($level === 2) {
            $msg['title'] = 'ESCALATED — not acknowledged: ' . $msg['title'];
        }
        if ($channelIds) {
            foreach (DB::table('escalation_channels')->whereIn('id', array_map('intval', $channelIds))->get() as $ch) {
                if (!$ch->is_active || in_array((int) $ch->id, $sent, true)) {
                    continue;
                }
                if (EscalationNotifier::rank($sev) < EscalationNotifier::rank($ch->min_severity)) {
                    $this->log($runId, $p->id, $ch->id, $ch->type, $ch->name, $msg['kind'], null, $level, 'skipped', null, 'Below the channel minimum severity (' . $ch->min_severity . ').', $msg['title']);
                    continue;
                }
                $sent[] = (int) $ch->id;
                [$ok, $http, $err] = $this->notifier->send(['type' => $ch->type, 'config' => EscalationNotifier::decryptConfig($ch->config)], $msg);
                $this->log($runId, $p->id, $ch->id, $ch->type, $ch->name, $msg['kind'], null, $level, $ok ? 'sent' : 'failed', $http, $err, $msg['title']);
                DB::table('escalation_channels')->where('id', $ch->id)->update(['last_status' => $ok ? 'sent' : 'failed', 'last_error' => $ok ? null : mb_substr((string) $err, 0, 255), 'last_sent_at' => $ok ? now() : DB::raw('last_sent_at')]);
            }
        }

        $people = [];
        if ($oncall) {
            $people = $this->oncallNow();
            if (!$people) {
                $this->log($runId, $p->id, null, 'oncall', 'On-call', $msg['kind'], null, $level, 'skipped', null, 'Nobody is on call right now.', $msg['title']);
            }
        }
        if ($admins) {
            $people = array_merge($people, $this->adminRecipients());
        }
        foreach ($people as $u) {
            $key = 'u:' . strtolower($u['email']); // نفس الشخص ميستلمش نفس التصعيد مرتين لو أكتر من قاعدة اتطبقت
            if (in_array($key, $sent, true)) {
                continue;
            }
            $sent[] = $key;
            [$ok, $http, $err] = $this->notifier->sendEmailTo($u['email'], $msg);
            $this->log($runId, $p->id, null, 'oncall', ($u['source'] ?? 'On-call') . ': ' . $u['full_name'], $msg['kind'], null, $level, $ok ? 'sent' : 'failed', $http, $err, $msg['title']);
        }
    }

    /** @return array<int,array{id:int,full_name:string,email:string,source:string}> */
    private function adminRecipients(): array
    {
        $rows = DB::table('users as u')->join('user_roles as ur', 'ur.user_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->whereIn('r.slug', ['security_admin', 'admin'])->where('u.status', 'active')->whereNull('u.deleted_at')->whereNotNull('u.email')
            ->select('u.id', 'u.full_name', 'u.email')->distinct()->limit(25)->get();

        return $rows->map(fn ($u) => ['id' => (int) $u->id, 'full_name' => (string) $u->full_name, 'email' => (string) $u->email, 'source' => 'Security admin'])->all();
    }

    private function log(?int $runId, ?int $policyId, ?int $channelId, string $type, ?string $name, string $kind, ?int $sourceId, int $level, string $status, ?int $http, ?string $error, ?string $title): void
    {
        try {
            DB::table('escalation_deliveries')->insert([
                'run_id' => $runId, 'policy_id' => $policyId, 'channel_id' => $channelId, 'channel_type' => $type,
                'channel_name' => $name !== null ? mb_substr($name, 0, 120) : null, 'kind' => $kind, 'source_id' => $sourceId,
                'level' => $level, 'status' => $status, 'http_status' => $http, 'error' => $error !== null ? mb_substr($error, 0, 255) : null,
                'title' => $title !== null ? mb_substr($title, 0, 200) : null, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Escalation delivery could not be logged: ' . $e->getMessage());
        }
    }

    // ───────────────────────── on-call ─────────────────────────

    /** @return array<int,array{id:int,full_name:string,email:string,source:string}> */
    public function oncallNow(): array
    {
        if (!Schema::hasTable('escalation_oncall_shifts')) {
            return [];
        }
        $rows = DB::table('escalation_oncall_shifts as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.starts_at', '<=', now())->where('s.ends_at', '>', now())->where('u.status', 'active')->whereNull('u.deleted_at')->whereNotNull('u.email')
            ->select('u.id', 'u.full_name', 'u.email')->distinct()->get();

        return $rows->map(fn ($u) => ['id' => (int) $u->id, 'full_name' => (string) $u->full_name, 'email' => (string) $u->email, 'source' => 'On-call'])->all();
    }

    /** @return array<string,mixed> */
    public function oncall(): array
    {
        $now = DB::table('escalation_oncall_shifts as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.starts_at', '<=', now())->where('s.ends_at', '>', now())->orderBy('s.starts_at')
            ->get(['s.id', 's.user_id', 'u.full_name', 'u.email', 's.starts_at', 's.ends_at', 's.note']);
        $shifts = DB::table('escalation_oncall_shifts as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.ends_at', '>', now()->subDays(1))->orderBy('s.starts_at')->limit(200)
            ->get(['s.id', 's.user_id', 'u.full_name', 'u.email', 's.starts_at', 's.ends_at', 's.note']);
        $next = $shifts->first(fn ($s) => $s->starts_at > now()->toDateTimeString());

        return ['now' => $now->map(fn ($r) => (array) $r)->all(), 'next' => $next ? (array) $next : null, 'shifts' => $shifts->map(fn ($r) => (array) $r)->all(), 'candidates' => $this->staffUsers()];
    }

    /** مستخدمو بورتال الأمان اللي ينفع يتحطوا في المناوبة. @return array<int,array<string,mixed>> */
    public function staffUsers(): array
    {
        return DB::table('users as u')->join('user_roles as ur', 'ur.user_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->whereIn('r.slug', ['security_admin', 'security_officer', 'admin'])->where('u.status', 'active')->whereNull('u.deleted_at')
            ->select('u.id', 'u.full_name', 'u.email')->distinct()->orderBy('u.full_name')->limit(200)->get()->map(fn ($r) => (array) $r)->all();
    }

    /** @throws \InvalidArgumentException */
    public function addShift(array $in, ?int $by, ?string $ip): int
    {
        $uid = (int) ($in['user_id'] ?? 0);
        $user = DB::table('users')->where('id', $uid)->where('status', 'active')->whereNull('deleted_at')->first(['id', 'email']);
        if (!$user || !$user->email) {
            throw new \InvalidArgumentException('Pick an active user with an email address.');
        }
        $start = $this->parseDate($in['starts_at'] ?? null);
        $end = $this->parseDate($in['ends_at'] ?? null);
        if (!$start || !$end || $end->lte($start)) {
            throw new \InvalidArgumentException('Shift end must be after its start.');
        }
        if ($start->diffInDays($end) > 31) {
            throw new \InvalidArgumentException('A single shift can be 31 days at most.');
        }
        $id = DB::table('escalation_oncall_shifts')->insertGetId([
            'user_id' => $uid, 'starts_at' => $start->toDateTimeString(), 'ends_at' => $end->toDateTimeString(),
            'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 200) ?: null, 'created_by' => $by, 'created_at' => now(),
        ]);
        $this->auditLog->record($by, 'escalation.oncall.added', 'EscalationShift', $id, null, ['user_id' => $uid, 'starts_at' => $start->toDateTimeString(), 'ends_at' => $end->toDateTimeString()], $ip);

        return $id;
    }

    public function deleteShift(int $id, ?int $by, ?string $ip): bool
    {
        $row = DB::table('escalation_oncall_shifts')->where('id', $id)->first();
        if (!$row) {
            return false;
        }
        DB::table('escalation_oncall_shifts')->where('id', $id)->delete();
        $this->auditLog->record($by, 'escalation.oncall.removed', 'EscalationShift', $id, ['user_id' => $row->user_id, 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at], null, $ip);

        return true;
    }

    private function parseDate($v): ?Carbon
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        try {
            return Carbon::parse($v)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    // ───────────────────────── tick (ack timeout + digests) ─────────────────────────

    /** @return array{escalated:int,acked:int,digests:int} بيتنادى كل دقيقة من `escalation:tick`. */
    public function tick(): array
    {
        $out = ['escalated' => 0, 'acked' => 0, 'digests' => 0];
        if (!Schema::hasTable('escalation_runs')) {
            return $out;
        }
        $due = DB::table('escalation_runs')->where('status', 'pending')->whereNotNull('next_check_at')->where('next_check_at', '<=', now())->orderBy('id')->limit(100)->get();
        $sent = [];
        foreach ($due as $run) {
            if ($this->isAcked($run)) {
                DB::table('escalation_runs')->where('id', $run->id)->update(['status' => 'acked', 'acked_at' => now(), 'next_check_at' => null]);
                $out['acked']++;
                continue;
            }
            $p = DB::table('escalation_policies')->where('id', $run->policy_id)->first();
            if (!$p || !$p->is_active) {
                DB::table('escalation_runs')->where('id', $run->id)->update(['status' => 'done', 'next_check_at' => null]);
                continue;
            }
            $msg = $this->message($run->source_type, (int) $run->source_id, $run->severity, '—', $run->title, 'Nobody acknowledged this within ' . (int) $p->ack_timeout_minutes . ' minutes.', []);
            $msg['fields'] = [ucfirst($run->source_type) => '#' . $run->source_id];
            $this->notify($p, (int) $run->id, 2, $msg, (array) json_decode((string) $p->level2_channel_ids, true), false, (bool) $p->level2_notify_admins, $sent);
            DB::table('escalation_runs')->where('id', $run->id)->update(['status' => 'escalated', 'level' => 2, 'next_check_at' => null]);
            $out['escalated']++;
        }

        foreach (DB::table('escalation_digests')->where('is_active', 1)->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->limit(20)->get() as $d) {
            $this->runDigest((int) $d->id);
            $out['digests']++;
        }

        // نضّف الأقدم من 90 يوم (مرة في الساعة تقريبًا).
        if ((int) now()->format('i') === 0) {
            DB::table('escalation_deliveries')->where('created_at', '<', now()->subDays(90))->delete();
            DB::table('escalation_runs')->where('created_at', '<', now()->subDays(180))->where('status', '!=', 'pending')->delete();
        }

        return $out;
    }

    private function isAcked(object $run): bool
    {
        $table = $run->source_type === 'incident' ? 'security_incidents' : 'security_alerts';
        $status = DB::table($table)->where('id', $run->source_id)->value('status');

        return $status === null || $status !== 'open';
    }

    // ───────────────────────── channels ─────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function channels(): array
    {
        return DB::table('escalation_channels')->orderBy('id')->get()->map(fn ($c) => $this->channelRow($c))->all();
    }

    /** @return array<string,mixed> */
    private function channelRow(object $c): array
    {
        return [
            'id' => (int) $c->id, 'name' => $c->name, 'type' => $c->type, 'min_severity' => $c->min_severity, 'is_active' => (bool) $c->is_active,
            'config' => EscalationNotifier::publicConfig($c->type, EscalationNotifier::decryptConfig($c->config)),
            'last_status' => $c->last_status, 'last_error' => $c->last_error, 'last_sent_at' => $c->last_sent_at, 'created_at' => $c->created_at,
        ];
    }

    /** @param array<string,mixed> $in @throws \InvalidArgumentException */
    public function saveChannel(?int $id, array $in, ?int $by, ?string $ip): array
    {
        $existing = $id ? DB::table('escalation_channels')->where('id', $id)->first() : null;
        if ($id && !$existing) {
            throw new \InvalidArgumentException('Channel not found.');
        }
        $type = $existing ? $existing->type : (string) ($in['type'] ?? '');
        if (!in_array($type, EscalationNotifier::TYPES, true)) {
            throw new \InvalidArgumentException('Pick a channel type: email, Slack, Telegram or webhook.');
        }
        $name = trim((string) ($in['name'] ?? ($existing->name ?? '')));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Give the channel a name (up to 120 characters).');
        }
        $min = (string) ($in['min_severity'] ?? ($existing->min_severity ?? 'high'));
        if (!in_array($min, ['low', 'warning', 'medium', 'high', 'critical'], true)) {
            throw new \InvalidArgumentException('Unknown minimum severity.');
        }
        $config = $this->notifier->normalizeConfig($type, $in['config'] ?? [], $existing ? EscalationNotifier::decryptConfig($existing->config) : []);
        $row = [
            'name' => $name, 'min_severity' => $min, 'config' => EscalationNotifier::encryptConfig($config),
            'is_active' => array_key_exists('is_active', $in) ? (int) filter_var($in['is_active'], FILTER_VALIDATE_BOOLEAN) : (int) ($existing->is_active ?? 1),
        ];
        if ($existing) {
            DB::table('escalation_channels')->where('id', $id)->update($row);
        } else {
            if (DB::table('escalation_channels')->count() >= 30) {
                throw new \InvalidArgumentException('You can have at most 30 channels.');
            }
            $id = DB::table('escalation_channels')->insertGetId($row + ['type' => $type, 'created_by' => $by, 'created_at' => now()]);
        }
        // الأسرار مش بتدخل سجل التدقيق.
        $this->auditLog->record($by, $existing ? 'escalation.channel.updated' : 'escalation.channel.created', 'EscalationChannel', $id, null, ['name' => $name, 'type' => $type, 'min_severity' => $min, 'is_active' => (bool) $row['is_active']], $ip);

        return $this->channelRow(DB::table('escalation_channels')->where('id', $id)->first());
    }

    public function deleteChannel(int $id, ?int $by, ?string $ip): bool
    {
        $c = DB::table('escalation_channels')->where('id', $id)->first();
        if (!$c) {
            return false;
        }
        DB::table('escalation_channels')->where('id', $id)->delete();
        // شيله من القواعد والملخصات.
        foreach (['escalation_policies' => ['channel_ids', 'level2_channel_ids'], 'escalation_digests' => ['channel_ids']] as $table => $cols) {
            foreach (DB::table($table)->get() as $row) {
                $upd = [];
                foreach ($cols as $col) {
                    $ids = (array) json_decode((string) $row->$col, true);
                    if (in_array($id, array_map('intval', $ids), true)) {
                        $upd[$col] = json_encode(array_values(array_filter($ids, fn ($x) => (int) $x !== $id)));
                    }
                }
                if ($upd) {
                    DB::table($table)->where('id', $row->id)->update($upd);
                }
            }
        }
        $this->auditLog->record($by, 'escalation.channel.deleted', 'EscalationChannel', $id, ['name' => $c->name, 'type' => $c->type], null, $ip);

        return true;
    }

    /** إرسال رسالة تجريبية على قناة. @return array{ok:bool,http_status:?int,error:?string} */
    public function testChannel(int $id, ?int $by, ?string $ip): array
    {
        $c = DB::table('escalation_channels')->where('id', $id)->first();
        if (!$c) {
            throw new \InvalidArgumentException('Channel not found.');
        }
        $msg = [
            'title' => 'Test message from UIPE Security Portal', 'lines' => ['If you can read this, the channel "' . $c->name . '" works.'],
            'severity' => 'high', 'url' => rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/') . '/security/escalation', 'kind' => 'test',
            'fields' => ['Sent by' => $by ? ('user #' . $by) : 'system'],
        ];
        [$ok, $http, $err] = $this->notifier->send(['type' => $c->type, 'config' => EscalationNotifier::decryptConfig($c->config)], $msg);
        $this->log(null, null, (int) $c->id, $c->type, $c->name, 'test', null, 1, $ok ? 'sent' : 'failed', $http, $err, $msg['title']);
        DB::table('escalation_channels')->where('id', $id)->update(['last_status' => $ok ? 'sent' : 'failed', 'last_error' => $ok ? null : mb_substr((string) $err, 0, 255), 'last_sent_at' => $ok ? now() : DB::raw('last_sent_at')]);
        $this->auditLog->record($by, 'escalation.channel.tested', 'EscalationChannel', $id, null, ['ok' => $ok], $ip);

        return ['ok' => $ok, 'http_status' => $http, 'error' => $err];
    }

    // ───────────────────────── policies ─────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function policies(): array
    {
        return DB::table('escalation_policies')->orderBy('id')->get()->map(fn ($p) => $this->policyRow($p))->all();
    }

    /** @return array<string,mixed> */
    private function policyRow(object $p): array
    {
        return [
            'id' => (int) $p->id, 'name' => $p->name, 'source' => $p->source, 'min_severity' => $p->min_severity,
            'types' => $p->types ? array_values((array) json_decode((string) $p->types, true)) : [],
            'channel_ids' => array_map('intval', (array) json_decode((string) $p->channel_ids, true)),
            'notify_oncall' => (bool) $p->notify_oncall,
            'ack_timeout_minutes' => $p->ack_timeout_minutes !== null ? (int) $p->ack_timeout_minutes : null,
            'level2_channel_ids' => array_map('intval', (array) json_decode((string) $p->level2_channel_ids, true)),
            'level2_notify_admins' => (bool) $p->level2_notify_admins, 'is_active' => (bool) $p->is_active, 'created_at' => $p->created_at,
        ];
    }

    /** @param int[] $ids @return int[] */
    private function validChannelIds($ids): array
    {
        $ids = array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));

        return $ids ? DB::table('escalation_channels')->whereIn('id', $ids)->pluck('id')->map(fn ($x) => (int) $x)->all() : [];
    }

    /** @param array<string,mixed> $in @throws \InvalidArgumentException */
    public function savePolicy(?int $id, array $in, ?int $by, ?string $ip): array
    {
        $existing = $id ? DB::table('escalation_policies')->where('id', $id)->first() : null;
        if ($id && !$existing) {
            throw new \InvalidArgumentException('Policy not found.');
        }
        $cur = $existing ? $this->policyRow($existing) : ['name' => '', 'source' => 'any', 'min_severity' => 'high', 'types' => [], 'channel_ids' => [], 'notify_oncall' => true, 'ack_timeout_minutes' => null, 'level2_channel_ids' => [], 'level2_notify_admins' => false, 'is_active' => true];
        $d = array_merge($cur, array_intersect_key($in, $cur));

        $name = trim((string) $d['name']);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Give the policy a name (up to 120 characters).');
        }
        if (!in_array($d['source'], self::SOURCES, true)) {
            throw new \InvalidArgumentException('Source must be any, alert or incident.');
        }
        if (!in_array($d['min_severity'], ['low', 'warning', 'medium', 'high', 'critical'], true)) {
            throw new \InvalidArgumentException('Unknown minimum severity.');
        }
        $types = array_values(array_unique(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 60), (array) $d['types']))));
        $channels = $this->validChannelIds($d['channel_ids']);
        $l2 = $this->validChannelIds($d['level2_channel_ids']);
        $oncall = (bool) filter_var($d['notify_oncall'], FILTER_VALIDATE_BOOLEAN);
        if (!$channels && !$oncall) {
            throw new \InvalidArgumentException('Pick at least one channel or turn on "notify the on-call person".');
        }
        $ack = $d['ack_timeout_minutes'] === null || $d['ack_timeout_minutes'] === '' ? null : (int) $d['ack_timeout_minutes'];
        $admins = (bool) filter_var($d['level2_notify_admins'], FILTER_VALIDATE_BOOLEAN);
        if ($ack !== null && ($ack < 5 || $ack > 1440)) {
            throw new \InvalidArgumentException('Acknowledgement timeout must be between 5 and 1440 minutes.');
        }
        if ($ack !== null && !$l2 && !$admins) {
            throw new \InvalidArgumentException('A timeout needs a second level: pick level-2 channels or notify security admins.');
        }

        $row = [
            'name' => $name, 'source' => $d['source'], 'min_severity' => $d['min_severity'], 'types' => $types ? json_encode($types) : null,
            'channel_ids' => json_encode($channels), 'notify_oncall' => (int) $oncall, 'ack_timeout_minutes' => $ack,
            'level2_channel_ids' => json_encode($l2), 'level2_notify_admins' => (int) $admins,
            'is_active' => (int) filter_var($d['is_active'], FILTER_VALIDATE_BOOLEAN),
        ];
        if ($existing) {
            DB::table('escalation_policies')->where('id', $id)->update($row);
        } else {
            if (DB::table('escalation_policies')->count() >= 50) {
                throw new \InvalidArgumentException('You can have at most 50 policies.');
            }
            $id = DB::table('escalation_policies')->insertGetId($row + ['created_by' => $by, 'created_at' => now()]);
        }
        $this->auditLog->record($by, $existing ? 'escalation.policy.updated' : 'escalation.policy.created', 'EscalationPolicy', $id, $existing ? $cur : null, $this->policyRow(DB::table('escalation_policies')->where('id', $id)->first()), $ip);

        return $this->policyRow(DB::table('escalation_policies')->where('id', $id)->first());
    }

    public function deletePolicy(int $id, ?int $by, ?string $ip): bool
    {
        $p = DB::table('escalation_policies')->where('id', $id)->first();
        if (!$p) {
            return false;
        }
        DB::table('escalation_policies')->where('id', $id)->delete();
        DB::table('escalation_runs')->where('policy_id', $id)->where('status', 'pending')->update(['status' => 'done', 'next_check_at' => null]);
        $this->auditLog->record($by, 'escalation.policy.deleted', 'EscalationPolicy', $id, $this->policyRow($p), null, $ip);

        return true;
    }

    // ───────────────────────── digests ─────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function digests(): array
    {
        return DB::table('escalation_digests')->orderBy('id')->get()->map(fn ($d) => $this->digestRow($d))->all();
    }

    /** @return array<string,mixed> */
    private function digestRow(object $d): array
    {
        return [
            'id' => (int) $d->id, 'name' => $d->name, 'frequency' => $d->frequency, 'hour' => (int) $d->hour, 'weekday' => $d->weekday !== null ? (int) $d->weekday : null,
            'channel_ids' => array_map('intval', (array) json_decode((string) $d->channel_ids, true)),
            'sections' => array_values((array) json_decode((string) $d->sections, true)),
            'is_active' => (bool) $d->is_active, 'last_run_at' => $d->last_run_at, 'next_run_at' => $d->next_run_at, 'last_status' => $d->last_status,
        ];
    }

    public function nextRun(string $frequency, int $hour, ?int $weekday, ?Carbon $from = null): Carbon
    {
        $from = $from ?: now();
        $t = $from->copy()->setTime($hour, 0, 0);
        if ($frequency === 'weekly') {
            $wd = $weekday ?: 1;
            while ((int) $t->isoWeekday() !== $wd || $t->lte($from)) {
                $t->addDay();
            }

            return $t;
        }

        return $t->lte($from) ? $t->addDay() : $t;
    }

    /** @param array<string,mixed> $in @throws \InvalidArgumentException */
    public function saveDigest(?int $id, array $in, ?int $by, ?string $ip): array
    {
        $existing = $id ? DB::table('escalation_digests')->where('id', $id)->first() : null;
        if ($id && !$existing) {
            throw new \InvalidArgumentException('Digest not found.');
        }
        $cur = $existing ? $this->digestRow($existing) : ['name' => '', 'frequency' => 'daily', 'hour' => 8, 'weekday' => null, 'channel_ids' => [], 'sections' => self::SECTIONS, 'is_active' => true];
        $d = array_merge($cur, array_intersect_key($in, $cur));
        $name = trim((string) $d['name']);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Give the digest a name (up to 120 characters).');
        }
        if (!in_array($d['frequency'], ['daily', 'weekly'], true)) {
            throw new \InvalidArgumentException('Frequency must be daily or weekly.');
        }
        $hour = (int) $d['hour'];
        if ($hour < 0 || $hour > 23) {
            throw new \InvalidArgumentException('Hour must be between 0 and 23.');
        }
        $weekday = $d['frequency'] === 'weekly' ? (int) ($d['weekday'] ?: 1) : null;
        if ($weekday !== null && ($weekday < 1 || $weekday > 7)) {
            throw new \InvalidArgumentException('Weekday must be 1 (Monday) to 7 (Sunday).');
        }
        $channels = $this->validChannelIds($d['channel_ids']);
        if (!$channels) {
            throw new \InvalidArgumentException('Pick at least one channel to send the digest to.');
        }
        $sections = array_values(array_intersect(self::SECTIONS, array_map('strval', (array) $d['sections'])));
        if (!$sections) {
            throw new \InvalidArgumentException('Pick at least one section.');
        }
        $active = (bool) filter_var($d['is_active'], FILTER_VALIDATE_BOOLEAN);
        $row = [
            'name' => $name, 'frequency' => $d['frequency'], 'hour' => $hour, 'weekday' => $weekday, 'channel_ids' => json_encode($channels),
            'sections' => json_encode($sections), 'is_active' => (int) $active,
            'next_run_at' => $active ? $this->nextRun($d['frequency'], $hour, $weekday)->toDateTimeString() : null,
        ];
        if ($existing) {
            DB::table('escalation_digests')->where('id', $id)->update($row);
        } else {
            if (DB::table('escalation_digests')->count() >= 20) {
                throw new \InvalidArgumentException('You can have at most 20 digests.');
            }
            $id = DB::table('escalation_digests')->insertGetId($row + ['created_by' => $by, 'created_at' => now()]);
        }
        $this->auditLog->record($by, $existing ? 'escalation.digest.updated' : 'escalation.digest.created', 'EscalationDigest', $id, null, ['name' => $name, 'frequency' => $d['frequency'], 'hour' => $hour, 'sections' => $sections], $ip);

        return $this->digestRow(DB::table('escalation_digests')->where('id', $id)->first());
    }

    public function deleteDigest(int $id, ?int $by, ?string $ip): bool
    {
        $d = DB::table('escalation_digests')->where('id', $id)->first();
        if (!$d) {
            return false;
        }
        DB::table('escalation_digests')->where('id', $id)->delete();
        $this->auditLog->record($by, 'escalation.digest.deleted', 'EscalationDigest', $id, ['name' => $d->name], null, $ip);

        return true;
    }

    /** بيبني الملخص ويبعته على قنوات الملخص (من غير فلتر الخطورة). @return array{sent:int,failed:int} */
    public function runDigest(int $id): array
    {
        $d = DB::table('escalation_digests')->where('id', $id)->first();
        if (!$d) {
            throw new \InvalidArgumentException('Digest not found.');
        }
        $since = $d->last_run_at ? Carbon::parse($d->last_run_at) : now()->subDays($d->frequency === 'weekly' ? 7 : 1);
        $lines = $this->digestLines((array) json_decode((string) $d->sections, true), $since);
        $msg = [
            'title' => $d->name . ' — ' . now()->format('Y-m-d'), 'lines' => $lines, 'severity' => 'info',
            'url' => rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/') . '/security/dashboard', 'kind' => 'digest',
            'fields' => ['Period' => $since->format('Y-m-d H:i') . ' → ' . now()->format('Y-m-d H:i')],
        ];
        $sent = 0;
        $failed = 0;
        foreach (DB::table('escalation_channels')->whereIn('id', array_map('intval', (array) json_decode((string) $d->channel_ids, true)))->where('is_active', 1)->get() as $ch) {
            [$ok, $http, $err] = $this->notifier->send(['type' => $ch->type, 'config' => EscalationNotifier::decryptConfig($ch->config)], $msg);
            $ok ? $sent++ : $failed++;
            $this->log(null, null, (int) $ch->id, $ch->type, $ch->name, 'digest', (int) $d->id, 1, $ok ? 'sent' : 'failed', $http, $err, $msg['title']);
        }
        DB::table('escalation_digests')->where('id', $id)->update([
            'last_run_at' => now(), 'last_status' => $failed === 0 && $sent > 0 ? 'sent' : ($sent > 0 ? 'partial' : 'failed'),
            'next_run_at' => $d->is_active ? $this->nextRun($d->frequency, (int) $d->hour, $d->weekday !== null ? (int) $d->weekday : null)->toDateTimeString() : null,
        ]);

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** @param string[] $sections @return string[] */
    private function digestLines(array $sections, Carbon $since): array
    {
        $lines = [];
        $sec = fn (string $name, callable $fn) => in_array($name, $sections, true) ? $this->safe($fn) : null;

        $r = $sec('alerts', function () use ($since) {
            $by = DB::table('security_alerts')->where('created_at', '>=', $since)->select('severity', DB::raw('COUNT(*) as c'))->groupBy('severity')->pluck('c', 'severity');
            $open = DB::table('security_alerts')->where('status', 'open')->whereIn('severity', ['high', 'critical'])->count();

            return 'Alerts: ' . ($by->sum() ?: 0) . ' new (critical ' . ($by['critical'] ?? 0) . ', high ' . ($by['high'] ?? 0) . ', warning ' . ($by['warning'] ?? 0) . ", info " . ($by['info'] ?? 0) . "); {$open} high/critical still open.";
        });
        $r && $lines[] = $r;

        $r = $sec('incidents', function () use ($since) {
            $new = DB::table('security_incidents')->where('detected_at', '>=', $since)->count();
            $openBy = DB::table('security_incidents')->whereIn('status', ['open', 'investigating', 'contained'])->select('severity', DB::raw('COUNT(*) as c'))->groupBy('severity')->pluck('c', 'severity');
            $breached = DB::table('security_incidents')->whereIn('status', ['open', 'investigating', 'contained'])->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->count();

            return "Incidents: {$new} new; open now — critical " . ($openBy['critical'] ?? 0) . ', high ' . ($openBy['high'] ?? 0) . ', medium ' . ($openBy['medium'] ?? 0) . ', low ' . ($openBy['low'] ?? 0) . "; {$breached} past SLA.";
        });
        $r && $lines[] = $r;

        $r = $sec('vulnerabilities', function () {
            $by = DB::table('vulnerabilities')->whereNotIn('status', ['resolved', 'verified', 'closed', 'accepted_risk'])->select('severity', DB::raw('COUNT(*) as c'))->groupBy('severity')->pluck('c', 'severity');

            return 'Open vulnerabilities: critical ' . ($by['critical'] ?? 0) . ', high ' . ($by['high'] ?? 0) . ', medium ' . ($by['medium'] ?? 0) . ', low ' . ($by['low'] ?? 0) . '.';
        });
        $r && $lines[] = $r;

        $r = $sec('audit', function () {
            $v = DB::table('audit_chain_verifications')->orderByDesc('id')->first(['status', 'created_at']);

            return 'Audit log integrity: ' . ($v ? "last check {$v->status} at {$v->created_at}." : 'never verified yet.');
        });
        $r && $lines[] = $r;

        return $lines ?: ['No data for the selected sections.'];
    }

    private function safe(callable $fn): ?string
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('Escalation digest section failed: ' . $e->getMessage());

            return null;
        }
    }

    // ───────────────────────── overview / deliveries ─────────────────────────

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $since = now()->subDays(7);
        $d = DB::table('escalation_deliveries')->where('created_at', '>=', $since)->select('status', DB::raw('COUNT(*) as c'))->groupBy('status')->pluck('c', 'status');
        $channels = DB::table('escalation_channels')->get(['type', 'is_active', 'last_status']);
        $pending = DB::table('escalation_runs')->where('status', 'pending')->whereNotNull('next_check_at')->count();
        $oc = $this->oncallNow();

        return [
            'channels' => ['total' => $channels->count(), 'active' => $channels->where('is_active', 1)->count(), 'failing' => $channels->where('is_active', 1)->where('last_status', 'failed')->count(),
                'by_type' => $channels->groupBy('type')->map->count()->all()],
            'policies' => ['total' => DB::table('escalation_policies')->count(), 'active' => DB::table('escalation_policies')->where('is_active', 1)->count()],
            'oncall' => ['now' => $oc, 'covered' => (bool) $oc],
            'digests' => ['total' => DB::table('escalation_digests')->count(), 'active' => DB::table('escalation_digests')->where('is_active', 1)->count(),
                'next_run_at' => DB::table('escalation_digests')->where('is_active', 1)->min('next_run_at')],
            'deliveries_7d' => ['sent' => (int) ($d['sent'] ?? 0), 'failed' => (int) ($d['failed'] ?? 0), 'skipped' => (int) ($d['skipped'] ?? 0)],
            'awaiting_ack' => $pending,
            'escalated_7d' => DB::table('escalation_runs')->where('status', 'escalated')->where('created_at', '>=', $since)->count(),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function deliveries(int $limit = 100, ?string $status = null, ?string $kind = null): array
    {
        return DB::table('escalation_deliveries')
            ->when($status && in_array($status, ['sent', 'failed', 'skipped'], true), fn ($q) => $q->where('status', $status))
            ->when($kind && in_array($kind, ['alert', 'incident', 'digest', 'test'], true), fn ($q) => $q->where('kind', $kind))
            ->orderByDesc('id')->limit(max(1, min(500, $limit)))->get()->map(fn ($r) => (array) $r)->all();
    }
}
