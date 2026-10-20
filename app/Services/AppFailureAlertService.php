<?php

namespace App\Services;

use App\Repositories\SettingRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * تنبيهات أعطال التطبيق (APK) للأدمنز: لما طلب من التطبيق يفشل (5xx، رفع ملف/فيديو فاشل، أو التطبيق نفسه يبلّغ بعطل
 * على الموبايل) بيتعمل إشعار داخلي لكل أدمن نشط — و NotificationService::notify() بيبعته push على الموبايل تلقائيًا
 * (Firebase) حتى لو التطبيق مقفول. أولوية "high" فبيعدّي كتم الفئات وساعات الهدوء.
 *
 * منع الإغراق (عشان تليفونك ما يرنّش 100 مرة لنفس العطل):
 *  - نفس (التوكن + النوع + الـ endpoint/العطل) بيتنبّه له مرة واحدة كل cooldown (الافتراضي 10 دقايق)، والمتكرر بيتعدّ
 *    ويظهر في التنبيه الجاي كـ "+N مرة منذ آخر تنبيه".
 *  - سقف عام: MAX_PER_HOUR تنبيه في الساعة لكل المنصة.
 * الإعدادات من Admin → Mobile → أي توكن → "تنبيهات الأعطال" (جدول settings، scope=global).
 */
class AppFailureAlertService
{
    public const TYPE = 'system_app_failure';
    private const MAX_PER_HOUR = 30;
    private const DEFAULTS = ['enabled' => true, 'server_errors' => true, 'upload_failures' => true, 'app_failures' => true, 'cooldown_minutes' => 10];

    public function __construct(private NotificationService $notifications, private SettingRepository $settings)
    {
    }

    /** @return array{enabled:bool,server_errors:bool,upload_failures:bool,app_failures:bool,cooldown_minutes:int} */
    public function settings(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => $def) {
            $raw = $this->settings->get('app_failure_alerts_' . $k, 'global', null, null);
            if ($raw === null) {
                $out[$k] = $def;
            } elseif (is_bool($def)) {
                $out[$k] = in_array((string) $raw, ['1', 'true', 'on'], true);
            } else {
                $out[$k] = max(1, min(1440, (int) $raw));
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $in */
    public function save(array $in): array
    {
        foreach (self::DEFAULTS as $k => $def) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = is_bool($def) ? (filter_var($in[$k], FILTER_VALIDATE_BOOLEAN) ? '1' : '0') : (string) max(1, min(1440, (int) $in[$k]));
            $this->settings->set('app_failure_alerts_' . $k, $v, 'global', null);
        }
        return $this->settings();
    }

    /**
     * @param string $kind  server_error | upload_error | app_failure
     * @param array<string,mixed> $c  status, method, path, route, user_id, device_id, user_agent, bytes, message, failure_kind
     */
    public function alert(int $tokenId, string $kind, array $c): void
    {
        try {
            $s = $this->settings();
            if (!$s['enabled']) {
                return;
            }
            if (($kind === 'server_error' && !$s['server_errors']) || ($kind === 'upload_error' && !$s['upload_failures']) || ($kind === 'app_failure' && !$s['app_failures'])) {
                return;
            }

            $what = (string) ($c['failure_kind'] ?? $c['route'] ?? $c['path'] ?? '');
            $key = 'appfail:' . sha1($tokenId . '|' . $kind . '|' . $what . '|' . (int) ($c['status'] ?? 0));
            if (!Cache::add($key . ':lock', 1, $s['cooldown_minutes'] * 60)) {
                Cache::add($key . ':n', 0, 86400);
                Cache::increment($key . ':n');
                return;
            }
            $more = (int) Cache::pull($key . ':n', 0);

            $hourKey = 'appfail:hour:' . now()->format('YmdH');
            Cache::add($hourKey, 0, 3700);
            if (Cache::increment($hourKey) > self::MAX_PER_HOUR) {
                return;
            }

            $this->sendToAdmins($tokenId, $kind, $c, $more);
        } catch (\Throwable $e) {
            // التنبيه ثانوي: أي مشكلة فيه ما توقّفش الطلب.
        }
    }

    /** إشعار تجريبي للأدمن اللي ضغط "جرّب التنبيه" (بيتخطّى الـ cooldown). */
    public function sendTest(int $userId, int $tokenId): void
    {
        $ar = $this->langOf($userId) === 'ar';
        $this->notifications->notify(
            $userId, self::TYPE,
            $ar ? '✅ تجربة تنبيهات الأعطال' : '✅ Failure alerts test',
            $ar ? 'لو وصلك الإشعار ده على الموبايل يبقى التنبيهات شغّالة. هتوصلك كده أي مشكلة من التطبيق فورًا.' : 'If you can read this on your phone, alerts are working. Any app problem will reach you like this, instantly.',
            "/admin/mobile/{$tokenId}?tab=log&status=error", 'high'
        );
    }

    private function langOf(int $userId): string
    {
        return DB::table('users')->where('id', $userId)->value('preferred_language') === 'en' ? 'en' : 'ar';
    }

    private function sendToAdmins(int $tokenId, string $kind, array $c, int $more): void
    {
        $admins = DB::table('users as u')
            ->join('user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.slug', 'admin')->where('u.status', 'active')->whereNull('u.deleted_at')
            ->distinct()->get(['u.id', 'u.preferred_language']);
        if ($admins->isEmpty()) {
            return;
        }

        $who = !empty($c['user_id']) ? DB::table('users')->where('id', (int) $c['user_id'])->value('full_name') : null;
        $link = "/admin/mobile/{$tokenId}?tab=log&status=" . ($kind === 'app_failure' ? 'client' : 'error');

        foreach ($admins as $a) {
            $ar = ($a->preferred_language ?? 'ar') !== 'en';
            [$title, $body] = $this->compose($kind, $c, $who, $more, $ar);
            $this->notifications->notify((int) $a->id, self::TYPE, $title, $body, $link, 'high');
        }
    }

    /** @return array{0:string,1:string} */
    private function compose(string $kind, array $c, ?string $who, int $more, bool $ar): array
    {
        $fk = (string) ($c['failure_kind'] ?? '');
        $isVideo = $fk === 'media_export_failed' || stripos((string) ($c['meta_hint'] ?? ''), 'video') !== false;
        $status = (int) ($c['status'] ?? 0);

        if ($kind === 'server_error') {
            $title = $ar ? "⚠️ خطأ سيرفر ({$status}) في طلب من التطبيق" : "⚠️ Server error ({$status}) on an app request";
        } elseif ($kind === 'upload_error') {
            $title = $ar ? "📤 فشل رفع من التطبيق ({$status})" : "📤 App upload failed ({$status})";
        } elseif ($fk === 'media_export_failed') {
            $title = $ar ? ($isVideo ? '🎬 فشل تجهيز الفيديو على الموبايل' : '🖼️ فشل تجهيز الملف على الموبايل') : '🎬 Media export failed on the phone';
        } elseif (in_array($fk, ['upload_direct_failed', 'upload_native_failed', 'upload_fetch_failed', 'message_upload_failed'], true)) {
            $title = $ar ? '📤 فشل رفع ملف/فيديو من التطبيق' : '📤 File/video upload failed in the app';
        } else {
            $title = $ar ? '📱 عطل في التطبيق' : '📱 App problem';
        }

        $parts = [];
        $parts[] = $who ?: ($ar ? 'مستخدم غير معروف' : 'Unknown user');
        if (!empty($c['path'])) {
            $parts[] = ($c['method'] ?? '') !== '' && $c['method'] !== 'LOCAL' ? $c['method'] . ' ' . $c['path'] : $c['path'];
        }
        if (isset($c['bytes']) && is_numeric($c['bytes']) && $c['bytes'] > 0) {
            $parts[] = $this->bytes((int) $c['bytes']);
        }
        if ($dev = $this->deviceName((string) ($c['user_agent'] ?? ''))) {
            $parts[] = $dev;
        }
        $body = implode(' · ', $parts);
        if (!empty($c['message'])) {
            $body .= "\n" . mb_substr((string) $c['message'], 0, 160);
        }
        if ($more > 0) {
            $body .= "\n" . ($ar ? "(+{$more} مرة كمان منذ آخر تنبيه)" : "(+{$more} more since the last alert)");
        }
        return [$title, $body];
    }

    private function bytes(int $n): string
    {
        return $n < 1048576 ? round($n / 1024, 1) . ' KB' : round($n / 1048576, 1) . ' MB';
    }

    private function deviceName(string $ua): string
    {
        if (preg_match('/Android [\d.]+; ([^;)]+?)(?: Build|\)|;)/', $ua, $m)) {
            return trim($m[1]);
        }
        return $ua !== '' ? 'Android' : '';
    }
}
