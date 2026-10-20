<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\SecurityPolicyRepository;
use App\Repositories\UserRepository;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * نسخة من AccountLockoutService القديمة — الإنفاذ الأساسي
 * (checkStatus/registerFailedAttempt/registerSuccessfulLogin، من بند
 * Auth) + updatePolicy()/manualUnlock()/lockedAccounts() (بند 25 batch 3،
 * لشاشة Security Policies). فرق شكلي فقط: SecurityPolicyRepository
 * (Eloquent) بدل DB::table الخام لقراءة/كتابة الصف.
 *
 * Logger::security() القديمة -> App\Support\SecurityLog::write() هنا
 * (بند 25 batch 4 — نفس شكل السطر بالظبط، مش Log:: العادية، عشان
 * SecurityLogRepository يقدر يقرأها فعليًا في شاشة Security & Audit Logs).
 *
 * ⚠️ لسه متعمّد مش موجود هنا: الـ in-app notification والـ security
 * alert وإيميل التنبيه عند القفل التلقائي (registerFailedAttempt) —
 * SecurityNotificationRepository::create()/MailService::sendAccountLockedNotice()
 * جاهزين، بس مش متوصّلين هنا لسه؛ القفل الفعلي (منع الدخول) شغال بالكامل
 * من غيرهم.
 */
class AccountLockoutService
{
    private const POLICY_KEY = 'login.lockout_policy';

    private const DEFAULTS = [
        'max_attempts'    => 5,
        'duration_unit'   => 'minutes',
        'duration_value'  => 15,
        'permanent_lock'  => false,
        'auto_unlock'     => true,
        'notify_inapp'    => false,
        'notify_email'    => false,
        'lock_message_ar' => 'تم قفل حسابك مؤقتًا بسبب محاولات دخول فاشلة متكررة. برجاء المحاولة مرة أخرى لاحقًا أو التواصل مع الدعم الفني.',
        'lock_message_en' => 'Your account has been temporarily locked due to repeated failed login attempts. Please try again later or contact support.',
        'support_email'   => 'support@uip.com',
        'support_phone'   => '',
    ];

    public function __construct(
        private SecurityPolicyRepository $policies,
        private UserRepository $users,
        private AuditLogService $auditLog,
        private MailService $mail
    ) {
    }

    public function getPolicy(): array
    {
        $row = $this->policies->findByKey(self::POLICY_KEY);
        $stored = [];
        if ($row) {
            $decoded = json_decode((string) $row->value, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }
        return array_merge(self::DEFAULTS, $stored);
    }

    /** @throws \InvalidArgumentException on bad input */
    public function updatePolicy(array $input, $adminUserId, ?string $ip = null): array
    {
        $before = $this->getPolicy();

        $maxAttempts = (int) ($input['max_attempts'] ?? 0);
        if ($maxAttempts < 1 || $maxAttempts > 50) {
            throw new \InvalidArgumentException('Maximum failed attempts must be between 1 and 50.');
        }

        $durationUnit = in_array($input['duration_unit'] ?? '', ['minutes', 'hours', 'days'], true)
            ? $input['duration_unit'] : 'minutes';

        $durationValue = (int) ($input['duration_value'] ?? 0);
        $permanent = !empty($input['permanent_lock']);
        if (!$permanent && ($durationValue < 1 || $durationValue > 3650)) {
            throw new \InvalidArgumentException('Lock duration must be between 1 and 3650.');
        }

        $policy = [
            'max_attempts'    => $maxAttempts,
            'duration_unit'   => $durationUnit,
            'duration_value'  => $permanent ? self::DEFAULTS['duration_value'] : $durationValue,
            'permanent_lock'  => $permanent,
            'auto_unlock'     => !empty($input['auto_unlock']),
            'notify_inapp'    => !empty($input['notify_inapp']),
            'notify_email'    => !empty($input['notify_email']),
            'lock_message_ar' => trim((string) ($input['lock_message_ar'] ?? '')) ?: self::DEFAULTS['lock_message_ar'],
            'lock_message_en' => trim((string) ($input['lock_message_en'] ?? '')) ?: self::DEFAULTS['lock_message_en'],
            'support_email'   => trim((string) ($input['support_email'] ?? '')),
            'support_phone'   => trim((string) ($input['support_phone'] ?? '')),
        ];

        if ($policy['support_email'] !== '' && !filter_var($policy['support_email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Support email address is not valid.');
        }

        $saved = $this->policies->updateValue(self::POLICY_KEY, json_encode($policy, JSON_UNESCAPED_UNICODE), $adminUserId);
        if (!$saved) {
            throw new \RuntimeException('Could not save the lockout policy.');
        }

        $this->auditLog->record($adminUserId, 'security_policy.lockout_updated', 'SecurityPolicy', null, $before, $policy, $ip);
        SecurityLog::write('Failed-login lockout policy updated', ['admin_id' => $adminUserId, 'ip' => $ip]);

        return $policy;
    }

    /**
     * @return array{locked:bool,message?:string,unlock_at?:?string}
     * بيعمل auto-unlock لأي قفل مؤقت خلصت مدته، زي القديم بالظبط.
     */
    public function checkStatus(User $user, string $locale = 'en'): array
    {
        $lockPermanent = (bool) $user->lock_permanent;
        $lockedUntil = $user->locked_until;

        if (!$lockPermanent && !$lockedUntil) {
            return ['locked' => false];
        }

        if (!$lockPermanent && $lockedUntil && $lockedUntil->isPast()) {
            $policy = $this->getPolicy();
            if ($policy['auto_unlock']) {
                DB::table('users')->where('id', $user->id)->update([
                    'failed_login_attempts' => 0,
                    'locked_until'          => null,
                    'lock_permanent'        => 0,
                    'lock_reason'           => null,
                    'locked_at'             => null,
                ]);
                SecurityLog::write('Account auto-unlocked (lock duration elapsed)', ['user_id' => $user->id]);
                return ['locked' => false];
            }
        }

        $policy = $this->getPolicy();
        $message = $this->composeLockMessage($policy, $lockPermanent, $lockedUntil?->toDateTimeString(), $locale);

        return ['locked' => true, 'message' => $message, 'unlock_at' => $lockPermanent ? null : $lockedUntil?->toDateTimeString()];
    }

    /** @return array{just_locked:bool,message?:string,remaining_attempts?:int} */
    public function registerFailedAttempt(User $user, string $ip, string $locale = 'en'): array
    {
        $policy = $this->getPolicy();

        $attempts = ((int) $user->failed_login_attempts) + 1;
        DB::table('users')->where('id', $user->id)->update(['failed_login_attempts' => $attempts]);

        if ($attempts < $policy['max_attempts']) {
            return ['just_locked' => false, 'remaining_attempts' => $policy['max_attempts'] - $attempts];
        }

        $permanent = (bool) $policy['permanent_lock'];
        $lockedUntil = null;
        if (!$permanent) {
            $seconds = $this->durationToSeconds($policy['duration_unit'], (int) $policy['duration_value']);
            $lockedUntil = now()->addSeconds($seconds);
        }

        DB::table('users')->where('id', $user->id)->update([
            'locked_until'   => $lockedUntil,
            'lock_permanent' => $permanent ? 1 : 0,
            'lock_reason'    => "Exceeded {$policy['max_attempts']} failed login attempts",
            'locked_at'      => now(),
        ]);

        $message = $this->composeLockMessage($policy, $permanent, $lockedUntil?->toDateTimeString(), $locale);
        SecurityLog::write('Account locked after failed login attempts', [
            'user_id' => $user->id, 'ip' => $ip, 'permanent' => $permanent, 'locked_until' => $lockedUntil?->toDateTimeString(),
        ]);

        try {
            if (!empty($policy['notify_inapp'])) {
                app(SecurityAlertService::class)->accountLocked((int) $user->id, (string) $user->email, $ip, $permanent, $lockedUntil?->toDateTimeString());
            }
        } catch (\Throwable $e) {
            Log::warning('Lockout alert failed: ' . $e->getMessage());
        }

        if (!empty($policy['notify_email'])) {
            $this->notifyOwnerOfLock($user, $ip, $permanent, $lockedUntil?->toDateTimeString(), $policy, $locale);
        }

        return ['just_locked' => true, 'message' => $message];
    }

    /**
     * بيبعت لصاحب الحساب نفسه إيميل "في حد حاول يدخل على حسابك" + لينك
     * إعادة تعيين الباسورد. بيتنادى مرة واحدة بس لحظة القفل (مش مع كل
     * محاولة فاشلة) عشان محدش يقدر يستخدمه في spam على صاحب الإيميل.
     * أي فشل هنا بيتسجل بس ومبيكسرش رد تسجيل الدخول.
     */
    private function notifyOwnerOfLock(User $user, string $ip, bool $permanent, ?string $lockedUntil, array $policy, string $locale): void
    {
        try {
            $plainToken = bin2hex(random_bytes(32));
            DB::table('password_reset_tokens')->insert([
                'user_id'    => $user->id,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addHour(),
                'created_at' => now(),
            ]);

            $resetUrl = \App\Support\PasswordResetLink::for((int) $user->id, $plainToken);

            $this->mail->sendAccountLockedNotice(
                (string) $user->email,
                (string) ($user->full_name ?? ''),
                $permanent,
                $lockedUntil,
                (string) ($policy['support_email'] ?? ''),
                (string) ($policy['support_phone'] ?? ''),
                (string) ($user->preferred_language ?? $locale),
                $resetUrl,
                $ip
            );
        } catch (\Throwable $e) {
            Log::error('Lockout notice email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    public function registerSuccessfulLogin(User $user): void
    {
        if ((int) $user->failed_login_attempts > 0) {
            DB::table('users')->where('id', $user->id)->update(['failed_login_attempts' => 0]);
        }
    }

    /** فتح قفل يدوي من مسؤول أمان — بند 25 batch 3 (شاشة Security Policies، لوحة "Locked Accounts"). */
    public function manualUnlock($userId, $adminUserId, ?string $ip = null): void
    {
        $this->users->unlockAccount($userId, $adminUserId);
        SecurityLog::write('Account manually unlocked by administrator', ['user_id' => $userId, 'admin_id' => $adminUserId]);
        Log::info('Account manually unlocked by administrator', ['user_id' => $userId, 'admin_id' => $adminUserId]);
        $this->auditLog->record($adminUserId, 'security.account_unlocked', 'User', $userId, null, null, $ip);
    }

    /** @return array<int,array<string,mixed>> كل حساب مقفول حاليًا — للوحة "Locked Accounts". */
    public function lockedAccounts(): array
    {
        return $this->users->lockedAccounts();
    }

    private function durationToSeconds(string $unit, int $value): int
    {
        return match ($unit) {
            'hours' => $value * 3600,
            'days'  => $value * 86400,
            default => $value * 60,
        };
    }

    private function composeLockMessage(array $policy, bool $permanent, ?string $lockedUntil, string $locale): string
    {
        $base = $locale === 'ar' ? $policy['lock_message_ar'] : $policy['lock_message_en'];

        if (!$permanent && $lockedUntil) {
            $base .= $locale === 'ar'
                ? ' سيتم فتح الحساب تلقائيًا في: ' . $lockedUntil
                : ' The account will automatically unlock at: ' . $lockedUntil;
        } elseif ($permanent) {
            $base .= $locale === 'ar'
                ? ' هذا القفل دائم ويتطلب تدخل مسؤول الأمان.'
                : ' This lock is permanent and requires a security administrator to unlock it.';
        }

        if (!empty($policy['support_email'])) {
            $base .= ($locale === 'ar' ? ' للدعم: ' : ' Support: ') . $policy['support_email'];
        }
        if (!empty($policy['support_phone'])) {
            $base .= ' / ' . $policy['support_phone'];
        }

        return $base;
    }
}
