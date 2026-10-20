<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * إيميلات أحداث مشروع التخرج (تقديم، قرار، تقييم، ملاحظة مشرف، قرب الموعد).
 *
 * NotificationService::notify() بيستدعي queueFor() بعد ما يسجّل الإشعار الداخلي، فأي كود بيبعت
 * إشعار مشروع (project_* / review_note / team_added) بيطلع له إيميل من غير ما نلمس مكان الاستدعاء.
 * الإيميل بيدخل جدول notification_email_queue، ومعالج الطابور (notifications:send-emails) هو
 * اللي بيرسل فعليًا عبر MailService — فالفشل المؤقت (SMTP واقع) بيتعاد بتأخير متزايد ومبيكسرش
 * الطلب الأصلي.
 *
 * بيحترم تفضيلات المستخدم: كتم فئة "projects"، تكرار الإرسال (فوري / ملخص يومي / أسبوعي)،
 * وساعات الهدوء. الأولوية high/urgent (زي آخر يوم قبل الموعد) بتتخطى الملخص وساعات الهدوء.
 */
class NotificationEmailService
{
    private const EXACT_TYPES = ['review_note', 'team_added'];
    private const TYPE_PREFIX = 'project_';
    private const MAX_PER_DIGEST = 50;
    /** إيميل فوري بقاله أكتر من كده من غير ما يتبعت = متقادم، منبعتوش. */
    private const STALE_HOURS = 24;

    public function __construct(private NotificationPreferencesService $prefs, private MailService $mail)
    {
    }

    public function shouldEmail(string $type): bool
    {
        return in_array($type, self::EXACT_TYPES, true) || str_starts_with($type, self::TYPE_PREFIX);
    }

    /** يحط إيميل الإشعار في الطابور (أو يتجاهله حسب النوع والتفضيلات). مبيرميش استثناء. */
    public function queueFor(Notification $n): void
    {
        try {
            if (!$this->shouldEmail((string) $n->type)) {
                return;
            }
            $user = DB::table('users')->where('id', $n->user_id)->whereNull('deleted_at')->where('status', 'active')
                ->first(['id', 'full_name', 'email', 'preferred_language']);
            if (!$user || trim((string) $user->email) === '') {
                return;
            }
            if (in_array('projects', $this->prefs->mutedCategoriesFor((int) $user->id), true)) {
                return;
            }

            $urgent = in_array((string) $n->priority, ['high', 'urgent'], true);
            $frequency = $urgent ? 'immediate' : $this->prefs->digestFrequencyFor((int) $user->id);
            $kind = $frequency === 'immediate' ? 'single' : 'digest';
            $due = $kind === 'digest' ? $this->nextDigestAt($frequency) : ($urgent ? now() : $this->afterQuietHours((int) $user->id, now()));

            DB::table('notification_email_queue')->insert([
                'user_id'         => $user->id,
                'notification_id' => $n->id,
                'kind'            => $kind,
                'to_email'        => $user->email,
                'to_name'         => $user->full_name,
                'subject'         => mb_substr((string) $n->title, 0, 200),
                'body'            => $n->body,
                'link_url'        => $this->absoluteUrl($n->link_url),
                'locale'          => $user->preferred_language === 'en' ? 'en' : 'ar',
                'status'          => 'pending',
                'attempts'        => 0,
                'max_attempts'    => 5,
                'next_attempt_at' => $due,
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotificationEmailService::queueFor failed', ['error' => $e->getMessage(), 'notification_id' => $n->id ?? null]);
        }
    }

    private function absoluteUrl(?string $link): ?string
    {
        if (!$link) {
            return null;
        }
        if (preg_match('#^https?://#i', $link)) {
            return $link;
        }
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url', '')), '/');
        return $base === '' ? null : $base . '/' . ltrim($link, '/');
    }

    /** لو الوقت جوه ساعات الهدوء، يرجّع نهايتها؛ وإلا نفس الوقت. */
    private function afterQuietHours(int $userId, Carbon $at): Carbon
    {
        $q = $this->prefs->quietHoursFor($userId);
        if (!$q['start'] || !$q['end']) {
            return $at;
        }
        [$sh, $sm] = array_map('intval', explode(':', $q['start']));
        [$eh, $em] = array_map('intval', explode(':', $q['end']));
        $start = $at->copy()->setTime($sh, $sm);
        $end = $at->copy()->setTime($eh, $em);
        if ($start <= $end) {                       // نافذة جوه نفس اليوم (مثلًا 13:00 → 15:00)
            return ($at >= $start && $at < $end) ? $end : $at;
        }
        if ($at >= $start) {                        // نافذة بتعدّي نص الليل، وإحنا بعد بدايتها
            return $end->copy()->addDay();
        }
        return $at < $end ? $end : $at;             // قبل نهايتها (الفجر)
    }

    private function nextDigestAt(string $frequency): Carbon
    {
        $next = now()->setTime(8, 0);
        if ($frequency === 'weekly') {
            $next = now()->next(Carbon::SUNDAY)->setTime(8, 0);
            return $next;
        }
        return $next->lte(now()) ? $next->addDay() : $next;
    }

    /**
     * يعالج الطابور المستحق: الإيميلات الفورية واحد واحد، والملخصات (إيميل واحد لكل مستخدم).
     * @return array{sent:int, failed:int, retried:int, expired:int, deferred:int, digests:int}
     */
    public function processDue(int $limit = 100, bool $dryRun = false): array
    {
        $r = ['sent' => 0, 'failed' => 0, 'retried' => 0, 'expired' => 0, 'deferred' => 0, 'digests' => 0];
        $now = now();

        if (!$dryRun) {
            $r['expired'] = DB::table('notification_email_queue')->where('status', 'pending')->where('kind', 'single')
                ->where('next_attempt_at', '<', $now->copy()->subHours(self::STALE_HOURS))
                ->update(['status' => 'failed', 'last_error' => 'expired']);
        }

        $singles = DB::table('notification_email_queue')->where('status', 'pending')->where('kind', 'single')
            ->where('next_attempt_at', '<=', $now)->orderBy('id')->limit($limit)->get();
        foreach ($singles as $row) {
            if ($dryRun) {
                $r['sent']++;
                continue;
            }
            if (!$this->claim($row->id)) {
                continue;
            }
            $ok = $this->mail->sendNotificationEmail((string) $row->to_email, (string) ($row->to_name ?? ''), (string) $row->subject, $row->body, $row->link_url, (string) $row->locale);
            $this->settle([$row->id], $ok, (int) $row->attempts, (int) $row->max_attempts, $r);
        }

        $userIds = DB::table('notification_email_queue')->where('status', 'pending')->where('kind', 'digest')
            ->where('next_attempt_at', '<=', $now)->distinct()->limit($limit)->pluck('user_id');
        foreach ($userIds as $uid) {
            $rows = DB::table('notification_email_queue')->where('status', 'pending')->where('kind', 'digest')
                ->where('user_id', $uid)->where('next_attempt_at', '<=', $now)->orderBy('id')->limit(self::MAX_PER_DIGEST)->get();
            if ($rows->isEmpty()) {
                continue;
            }
            if ($dryRun) {
                $r['digests']++;
                continue;
            }
            $ids = $rows->pluck('id')->all();
            $claimed = DB::table('notification_email_queue')->whereIn('id', $ids)->where('status', 'pending')->update(['status' => 'sending']);
            if ($claimed === 0) {
                continue;
            }
            $first = $rows->first();
            $items = $rows->map(fn ($x) => ['title' => (string) $x->subject, 'body' => $x->body, 'link' => $x->link_url])->all();
            $ok = $this->mail->sendNotificationDigest((string) $first->to_email, (string) ($first->to_name ?? ''), $items, (string) $first->locale);
            $r['digests']++;
            $this->settle($ids, $ok, (int) $first->attempts, (int) $first->max_attempts, $r);
        }

        return $r;
    }

    /** claim ذري: ينجح لو الصف لسه pending (بيمنع إرسال مرتين لو الـ scheduler اتكرر). */
    private function claim(int $id): bool
    {
        return DB::table('notification_email_queue')->where('id', $id)->where('status', 'pending')->update(['status' => 'sending']) === 1;
    }

    /** @param array<int,int> $ids */
    private function settle(array $ids, bool $ok, int $attempts, int $max, array &$r): void
    {
        $q = DB::table('notification_email_queue')->whereIn('id', $ids);
        if ($ok) {
            $q->update(['status' => 'sent', 'sent_at' => now(), 'attempts' => $attempts + 1, 'last_error' => null]);
            $r['sent'] += count($ids);
            return;
        }
        $error = mb_substr((string) ($this->mail->getLastError() ?? 'send failed'), 0, 500);
        // مفيش مزوّد بريد متظبط: مش خطأ في الإيميل نفسه، فمنحرقش محاولاته — نأجّله ونرجع نحاول بعدين.
        if (str_contains($error, 'No real mail provider')) {
            $q->update(['status' => 'pending', 'next_attempt_at' => now()->addMinutes(15), 'last_error' => $error]);
            $r['deferred'] += count($ids);
            return;
        }
        $attempts++;
        if ($attempts >= $max) {
            $q->update(['status' => 'failed', 'attempts' => $attempts, 'last_error' => $error]);
            $r['failed'] += count($ids);
            return;
        }
        $q->update(['status' => 'pending', 'attempts' => $attempts, 'last_error' => $error, 'next_attempt_at' => now()->addMinutes(min(60, 2 ** $attempts))]);
        $r['retried'] += count($ids);
    }
}
