<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\SecurityPolicyRepository;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * نسخة من PasswordPolicyService القديمة، كاملة دلوقتي — الأجزاء اللي كانت
 * بتستخدمها Register/ResetPassword (violations/assertValid/assertNotReused/
 * recordPasswordChange) اتنقلت في Auth (بند 1)، وبند 25 batch 3 ضاف
 * updatePolicy() + expiryStatus() (إدارة السياسة من لوحة الأدمن). فرق
 * شكلي فقط عن القديمة: SecurityPolicyRepository (Eloquent) بدل DB::table
 * الخام لقراءة/كتابة الصف. Logger::security() القديمة -> App\Support\
 * SecurityLog::write() (بند 25 batch 4).
 */
class PasswordPolicyService
{
    private const POLICY_KEY = 'password.policy';

    private const DEFAULTS = [
        'min_length'              => 8,
        'require_upper'           => true,
        'require_lower'           => true,
        'require_number'          => true,
        'require_special'         => true,
        'expire_days'             => 0,
        'reuse_prevent_count'     => 3,
        'warn_days_before_expiry' => 7,
    ];

    public function __construct(
        private SecurityPolicyRepository $policies,
        private AuditLogService $auditLog
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

    /**
     * @throws \InvalidArgumentException on bad input
     */
    public function updatePolicy(array $input, $adminUserId, ?string $ip = null): array
    {
        $before = $this->getPolicy();

        $minLength = (int) ($input['min_length'] ?? 0);
        if ($minLength < 6 || $minLength > 64) {
            throw new \InvalidArgumentException('Minimum password length must be between 6 and 64.');
        }

        $expireDays = (int) ($input['expire_days'] ?? 0);
        if ($expireDays < 0 || $expireDays > 3650) {
            throw new \InvalidArgumentException('Password expiration must be between 0 (never) and 3650 days.');
        }

        $reuseCount = (int) ($input['reuse_prevent_count'] ?? 0);
        if ($reuseCount < 0 || $reuseCount > 24) {
            throw new \InvalidArgumentException('Reuse-prevention count must be between 0 and 24.');
        }

        $warnDays = (int) ($input['warn_days_before_expiry'] ?? 0);
        if ($warnDays < 0 || $warnDays > 90) {
            throw new \InvalidArgumentException('Expiry warning window must be between 0 and 90 days.');
        }

        $policy = [
            'min_length'              => $minLength,
            'require_upper'           => !empty($input['require_upper']),
            'require_lower'           => !empty($input['require_lower']),
            'require_number'          => !empty($input['require_number']),
            'require_special'         => !empty($input['require_special']),
            'expire_days'             => $expireDays,
            'reuse_prevent_count'     => $reuseCount,
            'warn_days_before_expiry' => $warnDays,
        ];

        $saved = $this->policies->updateValue(self::POLICY_KEY, json_encode($policy, JSON_UNESCAPED_UNICODE), $adminUserId);
        if (!$saved) {
            throw new \RuntimeException('Could not save the password policy.');
        }

        $this->auditLog->record($adminUserId, 'security_policy.password_updated', 'SecurityPolicy', null, $before, $policy, $ip);
        SecurityLog::write('Password policy updated', ['admin_id' => $adminUserId, 'ip' => $ip]);
        Log::info('Password policy updated', ['admin_id' => $adminUserId, 'ip' => $ip]);

        return $policy;
    }

    /** @return string[] قائمة المخالفات (فاضية = كلمة السر صالحة) */
    public function violations(string $password): array
    {
        $p = $this->getPolicy();
        $errors = [];

        if (mb_strlen($password) < $p['min_length']) {
            $errors[] = "Password must be at least {$p['min_length']} characters long.";
        }
        if ($p['require_upper'] && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }
        if ($p['require_lower'] && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }
        if ($p['require_number'] && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }
        if ($p['require_special'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character.';
        }

        return $errors;
    }

    /** @throws \InvalidArgumentException */
    public function assertValid(string $password): void
    {
        $errors = $this->violations($password);
        if ($errors) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
    }

    /** @throws \InvalidArgumentException لو كلمة السر مطابقة لآخر N كلمة سر */
    public function assertNotReused($userId, string $newPassword): void
    {
        $p = $this->getPolicy();
        if ($p['reuse_prevent_count'] < 1 || !$userId) {
            return;
        }

        $rows = DB::table('password_history')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit((int) $p['reuse_prevent_count'])
            ->get(['password_hash']);

        foreach ($rows as $row) {
            if (password_verify($newPassword, $row->password_hash)) {
                throw new \InvalidArgumentException(
                    "For your security, you can't reuse one of your last {$p['reuse_prevent_count']} passwords."
                );
            }
        }
    }

    /** يسجل كلمة السر الجديدة في password_history ويحدّث password_changed_at. */
    public function recordPasswordChange($userId, string $newHash): void
    {
        DB::table('users')->where('id', $userId)->update([
            'password_changed_at'  => now(),
            'must_change_password' => 0,
        ]);

        DB::table('password_history')->insert([
            'user_id'       => $userId,
            'password_hash' => $newHash,
            'created_at'    => now(),
        ]);

        $keep = max(1, (int) $this->getPolicy()['reuse_prevent_count']) + 2;
        $keepIds = DB::table('password_history')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($keep)
            ->pluck('id');

        DB::table('password_history')
            ->where('user_id', $userId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    /**
     * منقولة من PasswordPolicyService::expiryStatus() القديمة بالظبط —
     * بند 25 batch 3، لعرض تنبيه انتهاء صلاحية كلمة السر في صفحات
     * الإعدادات. مفيش استدعاء ليها لسه في أي كنترولر (زي القديمة بالظبط
     * وقت إضافتها)، جاهزة لما تتوصل شاشة الإعدادات المناسبة.
     * @return array{expired:bool,expires_at:?string,days_left:?int,warn:bool}
     */
    public function expiryStatus($userId): array
    {
        $p = $this->getPolicy();
        if ($p['expire_days'] < 1) {
            return ['expired' => false, 'expires_at' => null, 'days_left' => null, 'warn' => false];
        }

        $user = User::find($userId);
        $changedAt = $user->password_changed_at ?? $user->created_at ?? null;
        if (!$user || !$changedAt) {
            return ['expired' => false, 'expires_at' => null, 'days_left' => null, 'warn' => false];
        }

        $expiresAt = strtotime((string) $changedAt) + ($p['expire_days'] * 86400);
        $daysLeft = (int) ceil(($expiresAt - time()) / 86400);

        return [
            'expired'    => $daysLeft < 0 || !empty($user->must_change_password),
            'expires_at' => date('Y-m-d', $expiresAt),
            'days_left'  => $daysLeft,
            'warn'       => $daysLeft >= 0 && $daysLeft <= $p['warn_days_before_expiry'],
        ];
    }
}
