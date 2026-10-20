<?php

namespace App\Services;

use App\Support\OtpGuard;
use Illuminate\Support\Facades\Cache;

/**
 * رمز OTP (8 أرقام) لإثبات ملكية الإيميل في استرجاع/دمج الحسابات المحذوفة.
 * بيتخزن مهشّر (HMAC) في الـ cache، محاولات غلط محدودة، وإعادة إرسال كل 60 ثانية.
 * الـ scope بيربط الرمز بالعملية بالظبط (restore:<id> أو merge:<من>:<إلى>) فرمز استرجاع مينفعش يتستخدم في دمج.
 */
class AccountRecoveryOtp
{
    public const OK = 'ok';
    public const EXPIRED = 'otp_expired';
    public const LOCKED = 'otp_locked';
    public const INVALID = 'otp_invalid';

    public function ttl(): int
    {
        return (int) config('account_recovery.otp.ttl_minutes', 10);
    }

    /** @return array{otp:?string, retry_after:int} otp=null لو لسه في فترة الانتظار */
    public function issue(string $scope): array
    {
        $key = $this->key($scope);
        // حد على العملية كلها (مش على الرمز بس): محظور مؤقتًا أو كتر الطلبات => مفيش رمز جديد.
        if ($blocked = OtpGuard::blockedSeconds($key)) {
            return ['otp' => null, 'retry_after' => $blocked];
        }
        $state = Cache::get($key);
        $resend = (int) config('account_recovery.otp.resend_seconds', 60);
        if ($state && (time() - (int) ($state['sent_at'] ?? 0)) < $resend) {
            return ['otp' => null, 'retry_after' => $resend - (time() - (int) $state['sent_at'])];
        }

        if ($wait = OtpGuard::registerSend($key)) {
            return ['otp' => null, 'retry_after' => $wait];
        }

        $otp = (string) random_int(10000000, 99999999);
        Cache::put($key, ['hash' => $this->hash($scope, $otp), 'attempts' => 0, 'sent_at' => time()], now()->addMinutes($this->ttl()));

        return ['otp' => $otp, 'retry_after' => 0];
    }

    /** يتحقق من غير ما يحرق الرمز (consume() بعد نجاح العملية كلها). */
    public function verify(string $scope, string $given): string
    {
        $key = $this->key($scope);
        if (OtpGuard::blockedSeconds($key) > 0) {
            Cache::forget($key);

            return self::LOCKED;
        }
        $state = Cache::get($key);
        if (!$state) {
            return self::EXPIRED;
        }
        if ((int) ($state['attempts'] ?? 0) >= (int) config('account_recovery.otp.max_attempts', 5)) {
            Cache::forget($key);

            return self::LOCKED;
        }
        $given = preg_replace('/\D+/', '', $given) ?? '';
        if (!hash_equals((string) $state['hash'], $this->hash($scope, $given))) {
            $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
            if (OtpGuard::recordFailure($key) > 0) {
                Cache::forget($key);

                return self::LOCKED;
            }
            Cache::put($key, $state, now()->addMinutes($this->ttl()));

            return self::INVALID;
        }
        OtpGuard::recordSuccess($key);

        return self::OK;
    }

    public function consume(string $scope): void
    {
        Cache::forget($this->key($scope));
    }

    private function key(string $scope): string
    {
        return 'account_recovery_otp:' . $scope;
    }

    private function hash(string $scope, string $otp): string
    {
        return hash_hmac('sha256', $scope . ':' . $otp, (string) config('app.key'));
    }
}
