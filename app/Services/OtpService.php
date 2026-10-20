<?php

namespace App\Services;

use App\Support\OtpGuard;
use Illuminate\Support\Facades\Cache;

/**
 * رموز OTP قصيرة (6 أرقام) للعمليات المهمة: تأكيد الإيميل، نسيان كلمة السر…
 *
 * - الرمز مبيتخزنش صريح: HMAC بمفتاح التطبيق + رقم المستخدم + الغرض، في الـ cache.
 * - صالح 10 دقايق ويُستخدم مرة واحدة.
 * - 5 محاولات غلط بس وبعدها الرمز بيتلغي ولازم يتطلب رمز جديد.
 * - إعادة الإرسال مسموحة كل 60 ثانية.
 * - حد على الحساب كله (OtpGuard): 5 غلطات متراكمة على أي رمز، أو أكتر من 5 رموز في الساعة => حظر 10 دقايق.
 * كل غرض (purpose) رمزه منفصل: رمز تأكيد الإيميل مينفعش لإعادة تعيين كلمة السر والعكس.
 */
class OtpService
{
    public const TTL_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_AFTER_SECONDS = 60;
    public const LENGTH = 6;

    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';
    public const LOCKED = 'locked';

    private function key(string $purpose, int $userId): string
    {
        return "otp:{$purpose}:{$userId}";
    }

    private function hash(string $purpose, int $userId, string $code): string
    {
        return hash_hmac('sha256', "{$purpose}:{$userId}:{$code}", (string) config('app.key'));
    }

    /** ثواني الانتظار قبل ما يتبعت رمز جديد (0 = مسموح دلوقتي). */
    public function secondsUntilResend(string $purpose, int $userId): int
    {
        if ($blocked = OtpGuard::blockedSeconds($this->scope($purpose, $userId))) {
            return $blocked;
        }
        $state = Cache::get($this->key($purpose, $userId));
        if (!$state) {
            return 0;
        }
        return max(0, self::RESEND_AFTER_SECONDS - (time() - (int) ($state['sent_at'] ?? 0)));
    }

    private function scope(string $purpose, int $userId): string
    {
        return "otp:{$purpose}:{$userId}";
    }

    /**
     * يولّد رمز جديد (بيلغي القديم) ويرجّعه. الكولر مسؤول عن فحص secondsUntilResend قبلها.
     * بيرجّع '' (من غير إنشاء رمز) لو الحساب محظور مؤقتًا أو عدّى حد الإرسال في الساعة — الكولر ميبعتش إيميل.
     */
    public function issue(string $purpose, int $userId): string
    {
        if (OtpGuard::registerSend($this->scope($purpose, $userId)) > 0) {
            return '';
        }
        $code = str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
        Cache::put($this->key($purpose, $userId), [
            'hash'       => $this->hash($purpose, $userId, $code),
            'attempts'   => 0,
            'sent_at'    => time(),
            'expires_at' => time() + self::TTL_MINUTES * 60,
        ], now()->addMinutes(self::TTL_MINUTES));

        return $code;
    }

    /** يفحص الرمز من غير ما يحرقه (الغلط بيتعدّ). بعد النجاح الكولر يستدعي forget() لما العملية تخلص. */
    public function check(string $purpose, int $userId, string $given): string
    {
        // Serialise checks for one code: without the lock, parallel requests could all read
        // "attempts = 0" and together try far more than MAX_ATTEMPTS guesses.
        try {
            return Cache::lock('lock:' . $this->key($purpose, $userId), 5)->block(3, fn () => $this->checkLocked($purpose, $userId, $given));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return self::INVALID;
        }
    }

    private function checkLocked(string $purpose, int $userId, string $given): string
    {
        $key = $this->key($purpose, $userId);
        $scope = $this->scope($purpose, $userId);
        if (OtpGuard::blockedSeconds($scope) > 0) {
            Cache::forget($key); // أثناء الحظر حتى الرمز الصح مبيتقبلش
            return self::LOCKED;
        }
        $state = Cache::get($key);
        if (!$state) {
            return self::EXPIRED;
        }
        if ((int) ($state['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            Cache::forget($key);
            return self::LOCKED;
        }

        $digits = preg_replace('/\D+/', '', $given);
        if (!hash_equals((string) $state['hash'], $this->hash($purpose, $userId, (string) $digits))) {
            $state['attempts'] = (int) $state['attempts'] + 1;
            if (OtpGuard::recordFailure($scope) > 0) {
                Cache::forget($key);
                return self::LOCKED;
            }
            if ($state['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($key);
                return self::LOCKED;
            }
            $left = max(1, (int) $state['expires_at'] - time());
            Cache::put($key, $state, now()->addSeconds($left));
            return self::INVALID;
        }

        OtpGuard::recordSuccess($scope);

        return self::OK;
    }

    public function forget(string $purpose, int $userId): void
    {
        Cache::forget($this->key($purpose, $userId));
    }

    /** رسالة خطأ جاهزة (عربي/إنجليزي) لنتيجة check() الفاشلة. */
    public static function errorMessage(string $result, bool $ar): string
    {
        return match ($result) {
            self::LOCKED  => OtpGuard::message($ar),
            self::EXPIRED => $ar ? 'الرمز منتهي أو لم يُرسل. اطلب رمز جديد.' : 'The code expired or was not requested. Request a new one.',
            default       => $ar ? 'الرمز غير صحيح.' : 'The code is incorrect.',
        };
    }
}
