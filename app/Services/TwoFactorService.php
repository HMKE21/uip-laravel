<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use App\Support\SecurityLog;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * كانت في بند 1 بس فيها verifyLoginCode() (اللي login flow محتاجه).
 * هنا (بند 6 — أول Settings surface يوصل لـ enrollment) بتتوسّع لـ
 * status()/generateSetup()/confirmSetup()/disable() — منقولة من
 * TwoFactorService القديمة، بفرق تصميم واحد متعمّد ومطابق لنفس القرار
 * اللي TwoFactorChallengeController اتخذه في بند 1 (شوف docblock بتاعه):
 *
 * القديمة بتخزن السر المؤقت (لسه مش متأكد منه) في PHP session
 * (_2fa_setup_secret) بين generateSetup() و confirmSetup(). إحنا API
 * Stateless بالكامل (JWT بس)، فالبديل: generateSetup() بترجع setup_token
 * (JWT قصير العمر — 10 دقايق، claim خاص typ=2fa_setup يحمل السر نفسه
 * + user id) والفرونت لازم يبعته تاني مع الكود لـ confirmSetup(). أأمن
 * من تخزين السر في الـ body/cookie، ومطابق لمبدأ challenge_token تمامًا.
 * TrustedDeviceService (تنضيف الأجهزة الموثوقة وقت disable) لسه مش منقولة
 * (بند 25، Security Portal، متأجل زي ما هو موثّق في بند 5) — disable()
 * هنا بتعمل كل حاجة تانية القديمة بتعملها.
 */
class TwoFactorService
{
    private const SETUP_TOKEN_TTL_SECONDS = 600;

    public function __construct(private UserRepository $users)
    {
    }

    public function status($userId): array
    {
        $user = $this->users->findById($userId);
        return [
            'enabled'      => (bool) ($user?->two_factor_enabled),
            'confirmed_at' => $user?->two_factor_confirmed_at,
        ];
    }

    /**
     * يبدأ (أو يعيد بدء) التسجيل: بيولّد سر جديد ويرجّعه جوه setup_token
     * موقّع (مش متخزن في الداتابيز لحد التأكيد)، زائد كل حاجة صفحة
     * Settings محتاجاها لعرض كارت التسجيل.
     */
    public function generateSetup($userId, string $accountEmail): array
    {
        $secret = Totp::generateSecret();
        $setupToken = UipJwtService::encode(
            ['typ' => '2fa_setup', 'sub' => (int) $userId, 'secret' => $secret],
            self::SETUP_TOKEN_TTL_SECONDS
        );

        return [
            'secret'      => $secret,
            'manual_key'  => Totp::formatForDisplay($secret),
            'otpauth_uri' => Totp::provisioningUri($secret, $accountEmail, 'UIP'),
            'setup_token' => $setupToken,
        ];
    }

    /**
     * بيتحقق من الكود المكوّن من 6 أرقام مقابل السر جوه setup_token؛ لو
     * صح، بيخزّن السر + recovery codes جديدة ويفعّل 2FA.
     * @return string[]|null recovery codes بالنص الصريح (تتعرض مرة واحدة)، أو null لو الكود غلط/التوكن منتهي
     */
    public function confirmSetup($userId, string $setupToken, string $code): ?array
    {
        $claims = UipJwtService::decode($setupToken);
        if (!$claims || ($claims['typ'] ?? null) !== '2fa_setup' || (int) ($claims['sub'] ?? 0) !== (int) $userId) {
            return null;
        }

        $secret = (string) ($claims['secret'] ?? '');
        if (!$secret || !Totp::verify($secret, $code)) {
            return null;
        }

        $recoveryCodes = Totp::generateRecoveryCodes(8);
        $hashed = array_map(fn ($c) => hash('sha256', $c), $recoveryCodes);

        $this->users->enableTwoFactor($userId, $secret, $hashed);

        SecurityLog::write('Two-factor authentication enabled', ['user_id' => $userId]);
        Log::info('Two-factor authentication enabled', ['user_id' => $userId]);

        return $recoveryCodes;
    }

    public function disable($userId, string $currentPassword): bool
    {
        $user = $this->users->findById($userId);
        if (!$user || !password_verify($currentPassword, (string) $user->password_hash)) {
            return false;
        }

        $this->users->disableTwoFactor($userId);
        SecurityLog::write('Two-factor authentication disabled', ['user_id' => $userId]);
        Log::info('Two-factor authentication disabled', ['user_id' => $userId]);
        return true;
    }

    /** يطابق verifyLoginCode() القديمة: كود TOTP حالي، أو recovery code لسه مايتاستخدمش. */
    public function verifyLoginCode(User $user, string $code): bool
    {
        if (!$user->two_factor_secret) {
            return false;
        }

        if (Totp::verify($user->two_factor_secret, $code)) {
            return true;
        }

        return $this->tryRecoveryCode($user, $code);
    }

    private function tryRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes;
        if (!is_array($codes) || !$codes) {
            return false;
        }

        $hash = hash('sha256', strtoupper(trim($code)));
        $index = array_search($hash, $codes, true);
        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        DB::table('users')->where('id', $user->id)->update([
            'two_factor_recovery_codes' => json_encode(array_values($codes)),
        ]);

        SecurityLog::write('Two-factor recovery code used', ['user_id' => $user->id]);

        return true;
    }
}
