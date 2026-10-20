<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * حماية مشتركة لكل رموز OTP (تأكيد الإيميل، نسيان كلمة السر، استرجاع/دمج الحسابات، "مش أنا"):
 * كل واحدة كانت بتحدّ محاولات الرمز الواحد بس، فاللي يغلط 5 مرات كان يطلب رمز جديد ويكمل تخمين للأبد.
 * هنا الحد على الحساب/العملية كلها (scope) مش على الرمز:
 *
 *  - أخطاء متراكمة: max_wrong غلطة (على أي رمز) خلال نافذة النافذة => حظر block_minutes دقيقة (افتراضي 5 غلطات => 10 دقايق).
 *  - طلبات إرسال: أكتر من max_sends رمز في الساعة => نفس الحظر. (الانتظار بين كل طلبين 60 ثانية لسه شغّال.)
 *  - أثناء الحظر: مفيش إرسال رمز جديد ومفيش فحص (حتى لو الرمز صح).
 *  - نجاح التحقق بيصفّر عدّاد الأخطاء.
 *
 * كله في الـ cache. الأرقام بيضبطها الأدمن من Admin > Platform Policy > OTP protection (PlatformPolicy::value('otp_*'))،
 * ولو الأدمن ما حفظش رقم بتفضل القيمة الافتراضية من config('security.otp') (env: OTP_MAX_WRONG, OTP_BLOCK_MINUTES, OTP_MAX_SENDS, OTP_SEND_WINDOW_MINUTES).
 * التغيير بيسري على الحظر/العدّادات الجديدة؛ اللي اتفعّل قبله بيكمّل بمدته الأصلية.
 */
final class OtpGuard
{
    public static function maxWrong(): int
    {
        return max(1, (int) PlatformPolicy::value('otp_max_wrong'));
    }

    public static function blockMinutes(): int
    {
        return max(1, (int) PlatformPolicy::value('otp_block_minutes'));
    }

    private static function maxSends(): int
    {
        return max(1, (int) PlatformPolicy::value('otp_max_sends'));
    }

    private static function sendWindow(): int
    {
        return max(1, (int) PlatformPolicy::value('otp_send_window_minutes')) * 60;
    }

    private static function k(string $kind, string $scope): string
    {
        return 'otpguard:' . $kind . ':' . $scope;
    }

    /** ثواني الحظر المتبقية (0 = مش محظور). */
    public static function blockedSeconds(string $scope): int
    {
        $until = (int) Cache::get(self::k('block', $scope), 0);

        return max(0, $until - time());
    }

    private static function block(string $scope): int
    {
        $secs = self::blockMinutes() * 60;
        Cache::put(self::k('block', $scope), time() + $secs, $secs);
        Cache::forget(self::k('fails', $scope));
        Cache::forget(self::k('sends', $scope));

        return $secs;
    }

    private static function bump(string $key, int $ttl): int
    {
        Cache::add($key, 0, $ttl);
        $n = Cache::increment($key);

        return is_int($n) ? $n : (int) Cache::get($key, 1);
    }

    /** غلطة جديدة. يرجّع ثواني الحظر لو الغلطة دي هي اللي فعّلته، وإلا 0. */
    public static function recordFailure(string $scope): int
    {
        $n = self::bump(self::k('fails', $scope), self::blockMinutes() * 60 * 3);

        return $n >= self::maxWrong() ? self::block($scope) : 0;
    }

    /** نجاح: يصفّر عدّاد الأخطاء. */
    public static function recordSuccess(string $scope): void
    {
        Cache::forget(self::k('fails', $scope));
    }

    /**
     * طلب إرسال رمز. يرجّع 0 لو مسموح، أو ثواني الانتظار لو محظور/عدّى حد الإرسال في الساعة.
     */
    public static function registerSend(string $scope): int
    {
        if ($left = self::blockedSeconds($scope)) {
            return $left;
        }
        $n = self::bump(self::k('sends', $scope), self::sendWindow());

        return $n > self::maxSends() ? self::block($scope) : 0;
    }

    /** رسالة جاهزة للحظر. */
    public static function message(bool $ar, ?int $seconds = null): string
    {
        $min = max(1, (int) ceil(($seconds ?? self::blockMinutes() * 60) / 60));

        return $ar
            ? "محاولات كتير. اتحظرت العملية مؤقتًا — حاول تاني بعد {$min} دقيقة."
            : "Too many attempts. This is temporarily blocked — try again in {$min} minute(s).";
    }
}
