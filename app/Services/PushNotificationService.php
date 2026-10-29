<?php

namespace App\Services;

use App\Services\Push\FcmClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Push للموبايل: تسجيل أجهزة اليوزر + إرسال أي إشعار داخلي (notifications) كـ push.
 * NotificationService::notify() بيستدعيها بعد ما يحفظ الإشعار، فكل مكان في المشروع بيبعت إشعار
 * بيطلع له push تلقائيًا. بتحترم كتم الفئات (Settings → Notifications) وساعات الهدوء؛
 * الأولوية high/urgent بتتخطاهم. فشل Firebase مبيكسرش حاجة (اتسجّل في اللوج بس).
 */
class PushNotificationService
{
    private const MAX_DEVICES_PER_USER = 10;

    public function __construct(private FcmClient $fcm, private NotificationPreferencesService $prefs)
    {
    }

    public function registerDevice(int $userId, string $token, ?string $deviceId, string $platform = 'android', ?string $locale = null, ?string $appVersion = null): void
    {
        $now = now();
        // نفس التثبيت اتغيّر له التوكن: امسح القديم.
        if ($deviceId) {
            DB::table('push_devices')->where('device_id', $deviceId)->where('token', '!=', $token)->delete();
        }
        $values = [
            'user_id'      => $userId,
            'device_id'    => $deviceId,
            'platform'     => $platform,
            'locale'       => $locale === 'en' ? 'en' : ($locale === 'ar' ? 'ar' : null),
            'app_version'  => $appVersion ? mb_substr($appVersion, 0, 32) : null,
            'last_seen_at' => $now,
        ];
        if (DB::table('push_devices')->where('token', $token)->exists()) {
            DB::table('push_devices')->where('token', $token)->update($values);   // بينقل الجهاز لليوزر الحالي لو اتغيّر
        } else {
            DB::table('push_devices')->insert($values + ['token' => $token, 'created_at' => $now]);
        }
        // سقف الأجهزة لكل يوزر: الأقدم يتشال.
        $extra = DB::table('push_devices')->where('user_id', $userId)->orderByDesc('last_seen_at')->orderByDesc('id')
            ->skip(self::MAX_DEVICES_PER_USER)->take(100)->pluck('id');
        if ($extra->isNotEmpty()) {
            DB::table('push_devices')->whereIn('id', $extra)->delete();
        }
    }

    public function unregisterDevice(int $userId, string $token): void
    {
        DB::table('push_devices')->where('user_id', $userId)->where('token', $token)->delete();
    }

    /** بيبعت الإشعار الداخلي رقم $notificationId لكل أجهزة صاحبه. مبيرميش استثناء. */
    public function sendForNotification(int $notificationId): int
    {
        try {
            if (!$this->fcm->isConfigured()) {
                return 0;
            }
            $n = DB::table('notifications')->where('id', $notificationId)->first();
            if (!$n || $n->is_deleted || $n->is_archived) {
                return 0;
            }
            $userId = (int) $n->user_id;
            $active = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->where('status', 'active')->exists();
            if (!$active) {
                return 0;
            }
            $urgent = in_array((string) $n->priority, ['high', 'urgent'], true);
            if (!$urgent) {
                if ($n->category && in_array((string) $n->category, $this->prefs->mutedCategoriesFor($userId), true)) {
                    return 0;
                }
                if ($this->inQuietHours($userId)) {
                    return 0;
                }
            }

            $devices = DB::table('push_devices')->where('user_id', $userId)->orderByDesc('last_seen_at')->limit(self::MAX_DEVICES_PER_USER)->get(['id', 'token']);
            if ($devices->isEmpty()) {
                return 0;
            }
            $unread = (int) DB::table('notifications')->where('user_id', $userId)->where('is_read', 0)->where('is_deleted', 0)->where('is_archived', 0)->count();
            $data = [
                'notification_id' => $n->id,
                'type'            => $n->type,
                'category'        => $n->category ?? '',
                'link_url'        => $this->safeLink($n->link_url),
                'unread'          => $unread,
            ];
            $title = (string) $n->title;
            $body = (string) ($n->body ?? '');

            // تنبيهات أعطال التطبيق: قناة "alerts" (أهمية قصوى) ولون أحمر، ونفس الـ tag بيستبدل القديم بدل ما يتكدّس.
            $opts = [];
            if (str_starts_with((string) $n->type, 'system_app_failure')) {
                $opts = ['channel_id' => 'uip-alerts', 'color' => '#E5484D', 'tag' => 'uip-appfail-' . substr(md5((string) $n->link_url), 0, 8)];
            }

            // شكل الإشعار حسب نوعه: أيقونة شريط الحالة + لون + (للشات) اسم المرسل كعنوان وتاج لكل محادثة.
            $ar = (DB::table('users')->where('id', $userId)->value('preferred_language') ?? 'ar') === 'ar';
            $type = (string) $n->type;
            if ($type === 'new_message') {
                if (preg_match('/^New message from\s+(.+)$/u', $title, $mm)) {
                    $title = trim($mm[1]);
                }
                if ($body === '' || $body === '(attachment)') {
                    $body = $ar ? '📎 مرفق' : '📎 Attachment';
                }
                // الرسالة الجديدة بتحل محل القديمة من نفس المحادثة، وصلاحية 7 أيام عشان تتسلّم لما الموبايل يرجع أونلاين.
                $opts = ['icon' => 'ic_stat_uip_message', 'color' => '#4F5BE8', 'count' => $unread,
                         'tag' => 'uip-msg-' . substr(md5((string) $n->link_url), 0, 12), 'ttl' => '604800s', 'ticker' => $title . ': ' . $body];
            } elseif ($opts === []) {
                if (str_contains($type, 'reject') || str_contains($type, 'fail') || str_contains($type, 'denied')) {
                    $opts = ['icon' => 'ic_stat_uip_alert', 'color' => '#E5484D'];
                    $title = '❌ ' . $title;
                } elseif (str_contains($type, 'approv') || str_contains($type, 'accept') || str_contains($type, 'success') || str_contains($type, 'graded')) {
                    $opts = ['icon' => 'ic_stat_uip_check', 'color' => '#1FA855'];
                    $title = '✅ ' . $title;
                } elseif (str_starts_with($type, 'ai_') || str_contains($type, '_ai_')) {
                    $opts = ['icon' => 'ic_stat_uip_spark', 'color' => '#8B5CF6'];
                    $title = '✨ ' . $title;
                } else {
                    $opts = ['icon' => 'ic_stat_uip', 'color' => '#4F5BE8'];
                }
                $opts['count'] = $unread;
            }

            $sent = 0;
            foreach ($devices as $d) {
                $r = $this->fcm->send($d->token, $title, $body !== '' ? $body : $title, $data, $opts);
                if ($r === FcmClient::OK) {
                    $sent++;
                } elseif ($r === FcmClient::INVALID_TOKEN) {
                    DB::table('push_devices')->where('id', $d->id)->delete();   // التطبيق اتمسح / التوكن انتهى
                }
            }
            return $sent;
        } catch (\Throwable $e) {
            Log::warning('PushNotificationService::sendForNotification failed', ['error' => $e->getMessage(), 'notification_id' => $notificationId]);
            return 0;
        }
    }

    /**
     * رنّة مكالمة واردة على كل أجهزة المستلم (data-only high priority → UipMessagingService بيرسم إشعار full-screen برنّة).
     * بتتخطى كتم الفئات وساعات الهدوء (المكالمة urgent زي الواتس). مبتحاولش لو المكالمة مبقتش ringing.
     */
    public function ringCall(int $callId, string $link = ''): int
    {
        try {
            if (!$this->fcm->isConfigured()) {
                return 0;
            }
            $call = DB::table('calls')->where('id', $callId)->first();
            if (!$call || $call->status !== 'ringing') {
                return 0;
            }
            $calleeId = (int) $call->callee_id;
            $active = DB::table('users')->where('id', $calleeId)->whereNull('deleted_at')->where('status', 'active')->exists();
            if (!$active) {
                return 0;
            }
            $devices = DB::table('push_devices')->where('user_id', $calleeId)->orderByDesc('last_seen_at')->limit(self::MAX_DEVICES_PER_USER)->get(['id', 'token']);
            if ($devices->isEmpty()) {
                return 0;
            }
            $caller = DB::table('users')->where('id', (int) $call->caller_id)->first(['full_name']);
            $ring = max(10, (int) config('calls.ring_seconds', 45));
            $elapsed = max(0, now()->getTimestamp() - \Illuminate\Support\Carbon::parse($call->created_at)->getTimestamp());
            $data = [
                'type'         => 'incoming_call',
                'call_id'      => (int) $call->id,
                'call_type'    => (string) $call->type,
                'caller_name'  => (string) ($caller->full_name ?? ''),
                'link_url'     => $this->safeLink($link),
                'ring_seconds' => max(5, $ring - $elapsed),
                'lang'         => (DB::table('users')->where('id', $calleeId)->value('preferred_language') ?? 'ar') === 'en' ? 'en' : 'ar',
            ];
            $sent = 0;
            foreach ($devices as $d) {
                $r = $this->fcm->sendData($d->token, $data, ['ttl' => max(10, $ring - $elapsed) . 's', 'timeout' => 4]);
                if ($r === FcmClient::OK) {
                    $sent++;
                } elseif ($r === FcmClient::INVALID_TOKEN) {
                    DB::table('push_devices')->where('id', $d->id)->delete();
                }
            }
            return $sent;
        } catch (\Throwable $e) {
            Log::warning('PushNotificationService::ringCall failed', ['error' => $e->getMessage(), 'call_id' => $callId]);
            return 0;
        }
    }

    /** يوقّف الرنّة على أجهزة المستلم (اتردّ عليها من جهاز تاني / المتصل قفل / فاتت / اترفضت). */
    public function stopCallRing(int $callId): int
    {
        try {
            if (!$this->fcm->isConfigured()) {
                return 0;
            }
            $call = DB::table('calls')->where('id', $callId)->first(['id', 'callee_id']);
            if (!$call) {
                return 0;
            }
            $devices = DB::table('push_devices')->where('user_id', (int) $call->callee_id)->orderByDesc('last_seen_at')->limit(self::MAX_DEVICES_PER_USER)->get(['id', 'token']);
            $sent = 0;
            foreach ($devices as $d) {
                $r = $this->fcm->sendData($d->token, ['type' => 'call_cancel', 'call_id' => (int) $call->id], ['ttl' => '60s', 'timeout' => 4]);
                if ($r === FcmClient::OK) {
                    $sent++;
                } elseif ($r === FcmClient::INVALID_TOKEN) {
                    DB::table('push_devices')->where('id', $d->id)->delete();
                }
            }
            return $sent;
        } catch (\Throwable $e) {
            Log::warning('PushNotificationService::stopCallRing failed', ['error' => $e->getMessage(), 'call_id' => $callId]);
            return 0;
        }
    }

    /** لينك داخلي بس (يبدأ بـ / وليس //)، غير كده مبيتبعتش عشان التطبيق مايفتحش عناوين خارجية. */
    private function safeLink(?string $link): string
    {
        $link = trim((string) $link);
        if ($link === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $link)) {
            $base = rtrim((string) (config('app.frontend_url') ?: config('app.url', '')), '/');
            if ($base !== '' && str_starts_with($link, $base . '/')) {
                return substr($link, strlen($base));
            }
            return '';
        }
        return ($link[0] === '/' && !str_starts_with($link, '//')) ? $link : '';
    }

    private function inQuietHours(int $userId): bool
    {
        $q = $this->prefs->quietHoursFor($userId);
        if (!$q['start'] || !$q['end']) {
            return false;
        }
        $now = now()->format('H:i');
        return $q['start'] <= $q['end']
            ? ($now >= $q['start'] && $now < $q['end'])
            : ($now >= $q['start'] || $now < $q['end']);
    }
}
