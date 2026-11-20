<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\DB;

/**
 * UIPE Mail — المرحلة 7: سياسات الاحتفاظ لكل جامعة (spam / sent / كل حاجة). السلة بتتمسح في MailboxService::purgeOldTrash.
 * الصناديق تحت Legal hold بتتخطّى دايمًا. المسودات والمجدول مبيتمسحوش.
 */
class MailRetentionService
{
    public function __construct(private MailboxService $mail, private MailAuditService $audit)
    {
    }

    /** @return array{spam:int,sent:int,all:int} (dry = عدّ بس من غير مسح) */
    public function run(bool $dry = false): array
    {
        $out = ['spam' => 0, 'sent' => 0, 'all' => 0];
        foreach (DB::table('mail_tenants')->where(fn ($q) => $q->whereNotNull('retention_spam_days')->orWhereNotNull('retention_sent_days')->orWhereNotNull('retention_all_days'))->get() as $t) {
            $mine = ['spam' => 0, 'sent' => 0, 'all' => 0];
            foreach (['spam' => 'retention_spam_days', 'sent' => 'retention_sent_days', 'all' => 'retention_all_days'] as $k => $col) {
                $days = (int) ($t->{$col} ?? 0);
                if ($days < 1) {
                    continue;
                }
                $cut = now()->subDays($days);
                do {
                    $q = DB::table('mail_items as i')->join('mail_accounts as a', 'a.id', '=', 'i.account_id')
                        ->where('a.tenant_id', $t->id)->where('a.legal_hold', 0)->where('i.sort_at', '<', $cut);
                    if ($k === 'all') {
                        $q->whereNotIn('i.folder', ['drafts', 'scheduled', 'outbox']);
                    } else {
                        $q->where('i.folder', $k);
                    }
                    if ($dry) {
                        $mine[$k] = (int) $q->count();
                        break;
                    }
                    $ids = $q->limit(2000)->pluck('i.id')->all();
                    if ($ids) {
                        $mine[$k] += DB::table('mail_items')->whereIn('id', $ids)->delete();
                    }
                } while (count($ids) === 2000);
                $out[$k] += $mine[$k];
            }
            if (!$dry && array_sum($mine) > 0) {
                $this->audit->record('mail.retention.purged', "Retention purge: spam={$mine['spam']} sent={$mine['sent']} all={$mine['all']}", ['university_id' => $t->university_id, 'tenant_id' => $t->id, 'target_type' => 'tenant', 'target_id' => $t->id, 'meta' => $mine]);
            }
        }
        if (!$dry) {
            $this->mail->purgeOrphans();
            $this->audit->prune();
            $this->pruneOperational();
        }
        return $out;
    }

    /** تنضيف بيانات التشغيل القديمة (أحداث أمنية مقفولة، سجل التسليم الخارجي، bounces). مفيهاش محتوى رسائل. */
    public function pruneOperational(): array
    {
        $ev = (int) config('mailbox.events_retention_days', 180);
        $dl = (int) config('mailbox.deliveries_retention_days', 90);
        $bn = (int) config('mailbox.bounces_retention_days', 365);
        return [
            'events' => $ev > 0 ? DB::table('mail_security_events')->whereIn('status', ['resolved', 'ignored'])->where('created_at', '<', now()->subDays($ev))->delete() : 0,
            'deliveries' => $dl > 0 ? DB::table('mail_external_deliveries')->whereIn('status', ['delivered', 'sent', 'suppressed', 'bounced'])->where('created_at', '<', now()->subDays($dl))->delete() : 0,
            'bounces' => $bn > 0 ? DB::table('mail_bounces')->where('created_at', '<', now()->subDays($bn))->delete() : 0,
        ];
    }
}
