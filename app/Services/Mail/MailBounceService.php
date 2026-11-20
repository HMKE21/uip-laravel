<?php

namespace App\Services\Mail;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** UIPE Mail — المرحلة 7: استقبال أحداث Brevo (webhook) → bounces + suppression + حالة التسليم. */
class MailBounceService
{
    /** حدث Brevo → نوع عندنا (null = مش bounce؛ بنحدّث الحالة بس). */
    private const MAP = ['hard_bounce' => 'hard', 'invalid_email' => 'hard', 'soft_bounce' => 'soft', 'blocked' => 'blocked', 'spam' => 'complaint', 'complaint' => 'complaint', 'unsubscribed' => 'complaint'];

    public function __construct(private MailAuditService $audit)
    {
    }

    /** رقم الرسالة من الـ tag اللي بنبعته (uipe-m123). */
    public static function tagFor(int $messageId): string
    {
        return 'uipe-m' . $messageId;
    }

    public static function messageIdFromTags($tags): ?int
    {
        foreach ((array) $tags as $t) {
            // Brevo أحيانًا بيبعت الـ tag كنص JSON (مثلًا ["uipe-m12"]) فبندوّر جوّه النص بدل التطابق الكامل.
            if (is_string($t) && preg_match('/(?<![A-Za-z0-9])uipe-m(\d+)(?![A-Za-z0-9])/', $t, $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /** @param array $p جسم الـ webhook (حدث واحد). @return string|null نتيجة: recorded | duplicate | ignored */
    public function handle(array $p): string
    {
        $event = strtolower(trim((string) ($p['event'] ?? '')));
        $email = strtolower(trim((string) ($p['email'] ?? '')));
        if ($event === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'ignored';
        }
        $mid = self::messageIdFromTags($p['tags'] ?? ($p['tag'] ?? []));
        $msg = $mid ? DB::table('mail_messages')->where('id', $mid)->first(['id', 'sender_account_id']) : null;
        if (!$msg) { // مش من عندنا (حدث لرسالة تانية على نفس حساب Brevo)
            return 'ignored';
        }
        $acc = DB::table('mail_accounts')->where('id', $msg->sender_account_id)->first(['id', 'university_id', 'tenant_id']);
        $uni = $acc && $acc->tenant_id && $acc->university_id ? (int) $acc->university_id : 0;

        if ($event === 'delivered') {
            DB::table('mail_external_deliveries')->where('message_id', $mid)->whereIn('status', ['sent', 'queued'])->update(['status' => 'delivered', 'updated_at' => now()]);
            return 'recorded';
        }
        $type = self::MAP[$event] ?? null;
        if (!$type) {
            return 'ignored';
        }
        $eid = (string) ($p['id'] ?? $p['event_id'] ?? '');
        $eid = $eid !== '' ? mb_substr($eid, 0, 64) : substr(sha1($event . '|' . $email . '|' . (string) ($p['message-id'] ?? '') . '|' . (string) ($p['ts_event'] ?? $p['ts'] ?? '')), 0, 40);
        $ins = DB::table('mail_bounces')->insertOrIgnore([
            'university_id' => $uni, 'message_id' => $mid, 'account_id' => $acc->id ?? null, 'recipient' => mb_substr($email, 0, 190), 'type' => $type,
            'reason' => isset($p['reason']) ? mb_substr((string) $p['reason'], 0, 250) : null, 'provider' => 'brevo', 'provider_event_id' => $eid, 'created_at' => now(),
        ]);
        if (!$ins) {
            return 'duplicate';
        }
        if (in_array($type, ['hard', 'complaint'], true)) {
            DB::table('mail_suppressions')->insertOrIgnore(['university_id' => $uni, 'address' => $email, 'reason' => $type === 'hard' ? 'hard_bounce' : 'complaint', 'note' => null, 'created_by' => null, 'created_at' => now()]);
        }
        if (in_array($type, ['hard', 'blocked', 'complaint'], true)) {
            DB::table('mail_external_deliveries')->where('message_id', $mid)->update(['status' => 'bounced', 'last_error' => mb_substr((string) ($p['reason'] ?? $type), 0, 250), 'updated_at' => now()]);
        }
        $this->audit->record('mail.bounce.' . $type, null, ['university_id' => $uni ?: null, 'account_id' => $acc->id ?? null, 'target_type' => 'message', 'target_id' => $mid, 'meta' => ['recipient' => $email]]);
        return 'recorded';
    }

    public function list(?int $universityId, array $f, int $page = 1, int $perPage = 30): array
    {
        $q = DB::table('mail_bounces as b')->leftJoin('mail_accounts as a', 'a.id', '=', 'b.account_id');
        if ($universityId !== null) {
            $q->where('b.university_id', $universityId);
        }
        if (!empty($f['type'])) {
            $q->where('b.type', (string) $f['type']);
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], strtolower(trim((string) $f['q']))) . '%';
            $q->where(fn ($w) => $w->where('b.recipient', 'like', $like)->orWhere('a.address', 'like', $like));
        }
        $perPage = min(100, max(5, $perPage));
        $total = (clone $q)->count();
        return ['items' => $q->orderByDesc('b.id')->forPage(max(1, $page), $perPage)->get(['b.*', 'a.address as sender'])->map(fn ($r) => [
            'id' => (int) $r->id, 'recipient' => $r->recipient, 'type' => $r->type, 'reason' => $r->reason, 'sender' => $r->sender, 'message_id' => $r->message_id ? (int) $r->message_id : null,
            'created_at' => Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
        ])->all(), 'total' => $total, 'page' => max(1, $page), 'per_page' => $perPage];
    }

    public function stats(?int $universityId): array
    {
        $b = fn () => DB::table('mail_bounces')->when($universityId !== null, fn ($q) => $q->where('university_id', $universityId));
        $d = fn () => DB::table('mail_external_deliveries')->when($universityId !== null, fn ($q) => $q->where('university_id', $universityId));
        $sent7 = (int) $d()->where('created_at', '>', now()->subDays(7))->whereIn('status', ['sent', 'delivered', 'bounced'])->count();
        $bounced7 = (int) $b()->where('created_at', '>', now()->subDays(7))->whereIn('type', ['hard', 'blocked', 'complaint'])->count();
        return [
            'by_type_7d' => $b()->where('created_at', '>', now()->subDays(7))->selectRaw('type, COUNT(*) c')->groupBy('type')->pluck('c', 'type')->map(fn ($c) => (int) $c)->all(),
            'bounce_rate_7d' => $sent7 > 0 ? round($bounced7 / $sent7 * 100, 1) : 0.0,
            'top_senders_7d' => DB::table('mail_bounces as b')->join('mail_accounts as a', 'a.id', '=', 'b.account_id')
                ->when($universityId !== null, fn ($q) => $q->where('b.university_id', $universityId))->where('b.created_at', '>', now()->subDays(7))
                ->selectRaw('a.address, COUNT(*) c')->groupBy('a.address')->orderByDesc('c')->limit(5)->get()->map(fn ($r) => ['address' => $r->address, 'count' => (int) $r->c])->all(),
        ];
    }
}
