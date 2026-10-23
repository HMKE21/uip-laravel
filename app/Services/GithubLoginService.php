<?php

namespace App\Services;

use App\Support\PlatformPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * الدخول / التسجيل بـ GitHub. بيستخدم نفس الـ OAuth App بتاع "Connect GitHub" (نفس client id / secret / redirect من Platform Policy)،
 * بس بـ scope هويّة (read:user user:email) والتوكن بيتمسح فورًا — مبنخزّنوش.
 *
 * GitHub مفيهوش ID token، فالسيرفر هو اللي بيبدّل الـ code (محتاج client secret) ويقرا الإيميل المتأكَّد منه من /user/emails.
 * الربط بين نافذة GitHub والمتصفح بيتم بـ nonce سرّي بيتولّد في تبويب الدخول نفسه (مبيتبعتش لـ GitHub):
 *   start(nonce)  -> رابط GitHub + state (في الكاش، مربوط بهاش الـ nonce)
 *   complete()    -> (callback عام) بيحفظ بروفايل GitHub في الكاش باسم هاش الـ nonce
 *   claim(nonce)  -> لازم نفس الـ nonce؛ بيسحب النتيجة مرة واحدة فقط
 * فمفيش حد يقدر "يسرق" دخول حد تاني أو يدخّل ضحية على حساب المهاجم (login CSRF) من غير الـ nonce.
 */
class GithubLoginService
{
    public const STATE_PREFIX = 'gl_';
    private const TTL = 600;
    private const API = 'https://api.github.com';

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->redirectUri() !== '';
    }

    private function clientId(): string { return trim((string) PlatformPolicy::value('github_client_id')); }
    private function clientSecret(): string { return trim((string) PlatformPolicy::value('github_client_secret')); }
    private function redirectUri(): string { return trim((string) PlatformPolicy::value('github_oauth_redirect')); }

    public static function ownsState(string $state): bool
    {
        return str_starts_with($state, self::STATE_PREFIX);
    }

    public static function validNonce(string $nonce): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{32,128}$/', $nonce);
    }

    /** @return string رابط صفحة موافقة GitHub */
    public function start(string $nonce): string
    {
        $state = self::STATE_PREFIX . bin2hex(random_bytes(24));
        Cache::put($this->stateKey($state), hash('sha256', $nonce), self::TTL);

        return 'https://github.com/login/oauth/authorize?' . http_build_query([
            'client_id'    => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'scope'        => 'read:user user:email',
            'state'        => $state,
            'allow_signup' => 'true',
        ]);
    }

    /**
     * بيتنادى من الـ callback العام. مبيرميش exception: النتيجة (نجاح أو سبب فشل) بتتحفظ للـ nonce.
     * @return bool true لو اتحفظت نتيجة نجاح
     */
    public function complete(string $code, string $state, ?string $oauthError = null): bool
    {
        $nonceHash = Cache::pull($this->stateKey($state)); // استخدام واحد
        if (!$nonceHash) {
            return false; // state منتهي أو متزوّر — مفيش نافذة مستنية نبلّغها
        }
        if ($oauthError) {
            return $this->store($nonceHash, ['error' => 'denied']);
        }

        try {
            $tokenRes = Http::acceptJson()->asForm()->timeout(15)->post('https://github.com/login/oauth/access_token', [
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'code'          => $code,
                'redirect_uri'  => $this->redirectUri(),
            ]);
            $token = $tokenRes->json('access_token');
            if (!$tokenRes->ok() || !$token) {
                Log::warning('GitHub login token exchange failed', ['status' => $tokenRes->status(), 'error' => $tokenRes->json('error')]);
                return $this->store($nonceHash, ['error' => 'failed']);
            }

            $me = $this->api($token)->get(self::API . '/user');
            if (!$me->ok() || !$me->json('id')) {
                return $this->store($nonceHash, ['error' => 'failed']);
            }

            // الإيميل لازم يكون متأكَّد منه عند GitHub (primary verified أولًا) — الإيميل العام في البروفايل مش مضمون.
            $emails = $this->api($token)->get(self::API . '/user/emails');
            $list = $emails->ok() ? (array) $emails->json() : [];
            $pick = null;
            foreach ($list as $e) {
                if (!empty($e['verified']) && !empty($e['primary']) && !empty($e['email'])) { $pick = $e['email']; break; }
            }
            if (!$pick) {
                foreach ($list as $e) {
                    if (!empty($e['verified']) && !empty($e['email'])) { $pick = $e['email']; break; }
                }
            }
            $pick = strtolower(trim((string) $pick));
            if ($pick === '' || !filter_var($pick, FILTER_VALIDATE_EMAIL) || str_ends_with($pick, '@users.noreply.github.com')) {
                return $this->store($nonceHash, ['error' => $pick === '' ? 'no_verified_email' : 'private_email']);
            }

            $login = (string) $me->json('login');
            $this->revoke($token); // مبنحتفظش بتوكن الدخول

            return $this->store($nonceHash, ['profile' => [
                'sub'     => (string) $me->json('id'),
                'email'   => $pick,
                'name'    => trim((string) ($me->json('name') ?: $login)),
                'picture' => $me->json('avatar_url'),
                'login'   => $login,
            ]]);
        } catch (\Throwable $e) {
            Log::warning('GitHub login failed', ['error' => $e->getMessage()]);
            return $this->store($nonceHash, ['error' => 'failed']);
        }
    }

    /**
     * بيسحب النتيجة (مرة واحدة). null = لسه مستني.
     * @return array{profile?:array,error?:string}|null
     */
    public function claim(string $nonce): ?array
    {
        $r = Cache::pull($this->resultKey(hash('sha256', $nonce)));
        return is_array($r) ? $r : null;
    }

    private function store(string $nonceHash, array $result): bool
    {
        Cache::put($this->resultKey($nonceHash), $result, 300);
        return isset($result['profile']);
    }

    /** إلغاء صلاحية التوكن عند GitHub (best-effort). */
    private function revoke(string $token): void
    {
        try {
            Http::withBasicAuth($this->clientId(), $this->clientSecret())
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'UIP-Platform'])
                ->timeout(8)
                ->send('DELETE', self::API . '/applications/' . rawurlencode($this->clientId()) . '/token', ['json' => ['access_token' => $token]]);
        } catch (\Throwable $e) {
            // مش مهم
        }
    }

    private function api(string $token)
    {
        return Http::withToken($token)->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'UIP-Platform'])->timeout(15);
    }

    private function stateKey(string $state): string
    {
        return 'github_login_state:' . hash('sha256', $state);
    }

    private function resultKey(string $nonceHash): string
    {
        return 'github_login_result:' . $nonceHash;
    }
}
