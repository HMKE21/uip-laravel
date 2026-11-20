<?php

namespace App\Services\Mail;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** UIPE Mail — المرحلة 7: مراقبة الـ Queue (المجدول/Undo/الإرسال الخارجي). بدون موضوع أو نص الرسائل. */
class MailQueueService
{
    public const HEARTBEAT = 'mail:queue:heartbeat';

    public function heartbeat(array $stats = []): void
    {
        Cache::put(self::HEARTBEAT, ['at' => now()->toIso8601String()] + $stats, now()->addDay());
    }

    public function stats(?int $universityId): array
    {
        $uid = $universityId;
        $msgs = fn () => DB::table('mail_messages as m')->join('mail_accounts as a', 'a.id', '=', 'm.sender_account_id')->when($uid !== null, fn ($q) => $q->where('a.university_id', $uid));
        $del = fn () => DB::table('mail_external_deliveries')->when($uid !== null, fn ($q) => $q->where('university_id', $uid));
        $due = fn () => $msgs()->where('m.is_draft', 1)->whereIn('m.send_state', ['scheduled', 'undo']);
        $hb = Cache::get(self::HEARTBEAT);
        $lastAt = $hb['at'] ?? null;
        $oldestDue = (clone $due())->where('m.scheduled_at', '<=', now())->min('m.scheduled_at');
        return [
            'worker' => [
                'last_run_at' => $lastAt, 'healthy' => $lastAt ? Carbon::parse($lastAt)->gt(now()->subMinutes(5)) : false,
                'last_result' => array_diff_key((array) $hb, ['at' => 1]),
            ],
            'pending' => [
                'scheduled' => (int) $due()->where('m.send_state', 'scheduled')->count(),
                'undo_window' => (int) $due()->where('m.send_state', 'undo')->count(),
                'overdue' => (int) $due()->where('m.scheduled_at', '<=', now()->subMinutes(2))->count(),
                'oldest_overdue_at' => $oldestDue ? Carbon::parse($oldestDue, 'UTC')->toIso8601String() : null,
                'failed_drafts' => (int) $msgs()->where('m.is_draft', 1)->whereNotNull('m.schedule_error')->where('m.updated_at', '>', now()->subDays(7))->count(),
            ],
            'external' => [
                'enabled' => app(ExternalMailGateway::class)->enabled(),
                'by_status' => $del()->where('created_at', '>', now()->subDays(7))->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c)->all(),
                'retrying' => (int) $del()->where('status', 'failed')->whereNotNull('next_retry_at')->count(),
                'gave_up' => (int) $del()->where('status', 'failed')->whereNull('next_retry_at')->where('updated_at', '>', now()->subDays(7))->count(),
            ],
        ];
    }

    public function deliveries(?int $universityId, array $f, int $page = 1, int $perPage = 30): array
    {
        $q = DB::table('mail_external_deliveries as d')->join('mail_accounts as a', 'a.id', '=', 'd.sender_account_id')->when($universityId !== null, fn ($x) => $x->where('d.university_id', $universityId));
        if (!empty($f['status'])) {
            $q->where('d.status', (string) $f['status']);
        }
        $perPage = min(100, max(5, $perPage));
        $total = (clone $q)->count();
        return ['items' => $q->orderByDesc('d.id')->forPage(max(1, $page), $perPage)->get(['d.*', 'a.address as sender'])->map(fn ($r) => [
            'id' => (int) $r->id, 'message_id' => (int) $r->message_id, 'sender' => $r->sender, 'status' => $r->status, 'recipients' => (int) $r->recipients, 'attempts' => (int) $r->attempts,
            'last_error' => $r->last_error, 'next_retry_at' => $r->next_retry_at ? Carbon::parse($r->next_retry_at, 'UTC')->toIso8601String() : null,
            'created_at' => Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
        ])->all(), 'total' => $total, 'page' => max(1, $page), 'per_page' => $perPage];
    }

    /** يخلّي التسليم الفاشل يتجرّب في أول دورة (بيصفّر العدّاد لو كان استسلم). */
    public function requeue(?int $universityId, int $id): array
    {
        $d = DB::table('mail_external_deliveries')->where('id', $id)->when($universityId !== null, fn ($q) => $q->where('university_id', $universityId))->first();
        if (!$d) {
            throw new \DomainException('Delivery not found.', 404);
        }
        if ($d->status !== 'failed') {
            throw new \DomainException('Only failed deliveries can be retried.', 422);
        }
        DB::table('mail_external_deliveries')->where('id', $id)->update(['attempts' => 0, 'next_retry_at' => now(), 'updated_at' => now()]);
        return ['id' => $id, 'status' => 'failed', 'requeued' => true];
    }
}
