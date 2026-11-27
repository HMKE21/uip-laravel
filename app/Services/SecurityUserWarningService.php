<?php

namespace App\Services;

use App\Services\Tenancy\TenantContext;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Live Threat Monitor — تحذير مستخدم مسجّل من شاشة تفاصيل التهديد.
 *
 * بيبعت إشعار داخلي (جرس + Push + إيميل لو مفعّل) للمستخدم بلغته المفضّلة، ويسجّل العملية في الـ audit + security log.
 * مفيش حظر ولا قفل هنا — ده تنبيه بس. الإجراءات الأشد (إنهاء الجلسات/القفل) بتروح لمركز التحقيق.
 */
class SecurityUserWarningService
{
    /** preset => [عنوان en, عنوان ar, نص en, نص ar] */
    public const PRESETS = [
        'suspicious_signin' => [
            'Security notice: unusual sign-in to your account',
            'تنبيه أمني: تسجيل دخول غير معتاد على حسابك',
            'We noticed a sign-in to your account from an unusual address or location. If this was not you, change your password immediately and contact the security team.',
            'رصدنا تسجيل دخول إلى حسابك من عنوان أو موقع غير معتاد. إذا لم تكن أنت، غيّر كلمة المرور فورًا وتواصل مع فريق الأمان.',
        ],
        'vpn_proxy' => [
            'Security notice: VPN / proxy connection detected',
            'تنبيه أمني: اتصال عبر VPN أو بروكسي',
            'Your account is connecting through a VPN or proxy. Please connect directly — some connections through anonymizing services may be restricted for security reasons.',
            'حسابك يتصل عبر VPN أو بروكسي. يُرجى الاتصال مباشرة — قد تُقيَّد بعض الاتصالات عبر خدمات إخفاء الهوية لأسباب أمنية.',
        ],
        'policy' => [
            'Security notice: activity on your account',
            'تنبيه أمني: نشاط على حسابك',
            'Activity on your account was flagged as going against the platform’s safe-use policy. Please follow the policy to avoid restrictions on your account.',
            'تم رصد نشاط على حسابك يخالف سياسة الاستخدام الآمن للمنصة. يُرجى الالتزام بالسياسة لتجنّب تقييد حسابك.',
        ],
        'custom' => [
            'Security notice about your account',
            'تنبيه أمني بخصوص حسابك',
            '',
            '',
        ],
    ];

    public function __construct(private NotificationService $notifications, private AuditLogService $audit)
    {
    }

    /**
     * @return array{ok:bool,error:?string,status:int,user?:array<string,mixed>}
     */
    public function warn(int $targetId, int $actorId, string $preset, string $note, ?string $subjectIp, ?string $actorIp): array
    {
        if ($targetId <= 0 || !isset(self::PRESETS[$preset])) {
            return ['ok' => false, 'error' => 'Choose a valid warning type.', 'status' => 422];
        }
        $note = trim($note);
        if ($preset === 'custom' && mb_strlen($note) < 5) {
            return ['ok' => false, 'error' => 'Write the warning message (at least 5 characters).', 'status' => 422];
        }
        if (mb_strlen($note) > 500) {
            return ['ok' => false, 'error' => 'The message is too long (500 characters max).', 'status' => 422];
        }
        if ($targetId === $actorId) {
            return ['ok' => false, 'error' => 'You cannot send a warning to yourself.', 'status' => 422];
        }
        $u = DB::table('users')->where('id', $targetId)->whereNull('deleted_at')->first(['id', 'full_name', 'preferred_language']);
        if (!$u) {
            return ['ok' => false, 'error' => 'User not found.', 'status' => 404];
        }
        // منع الإزعاج: تحذير واحد كل دقيقة لنفس المستخدم.
        if (!Cache::add('thrmon:warn:' . TenantContext::ns() . $targetId, 1, 60)) {
            return ['ok' => false, 'error' => 'A warning was just sent to this user. Wait a minute before sending another.', 'status' => 429];
        }

        $ar = ($u->preferred_language ?? 'ar') === 'ar';
        [$titleEn, $titleAr, $bodyEn, $bodyAr] = self::PRESETS[$preset];
        $title = $ar ? $titleAr : $titleEn;
        $body = $ar ? $bodyAr : $bodyEn;
        if ($note !== '') {
            $body = $body === '' ? $note : $body . "\n\n" . ($ar ? 'ملاحظة من فريق الأمان: ' : 'Note from the security team: ') . $note;
        }

        $this->notifications->notify($targetId, 'security_warning', $title, $body, null, 'high');

        $detail = ['preset' => $preset, 'ip' => $subjectIp, 'note' => $note !== '' ? mb_substr($note, 0, 200) : null];
        $this->audit->record($actorId, 'security.threat.warn_user', 'User', $targetId, null, $detail, $actorIp);
        // ip هنا = عنوان الموظف (مش المستخدم المحذَّر) — بنمرّره صراحة عشان العملية متتنسبش لعنوان المستخدم في الربط بالحسابات.
        SecurityLog::write('Threat warning sent to user', ['user_id' => $targetId, 'actor_id' => $actorId, 'ip' => $actorIp] + $detail);

        return ['ok' => true, 'error' => null, 'status' => 200, 'user' => ['id' => (int) $u->id, 'full_name' => $u->full_name]];
    }
}
