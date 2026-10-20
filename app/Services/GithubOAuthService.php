<?php

namespace App\Services;

use App\Models\GithubConnection;
use App\Models\ProjectLink;
use App\Support\PlatformPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Connect GitHub (OAuth) — الطالب بيدي المنصة صلاحية، والمنصة بتجيب مستودعاته
 * تلقائيًا (زي Vercel). التوكن بيتخزن مشفّر، والـ state بيتخزن في الكاش
 * (دقائق قليلة، استخدام واحد) ومربوط بالمستخدم عشان الـ callback (من غير
 * Authorization header) يعرف مين اللي بيربط.
 */
class GithubOAuthService
{
    private const STATE_TTL_SECONDS = 600;
    private const API = 'https://api.github.com';

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->redirectUri() !== '';
    }

    // القيم بتتقرأ من لوحة الأدمن (Platform Policy) أولًا، وبعدين من .env لو الأدمن مدخلهاش.
    private function clientId(): string { return trim((string) PlatformPolicy::value('github_client_id')); }
    private function clientSecret(): string { return trim((string) PlatformPolicy::value('github_client_secret')); }
    private function redirectUri(): string { return trim((string) PlatformPolicy::value('github_oauth_redirect')); }

    /** @return string رابط صفحة موافقة GitHub */
    public function authorizeUrl(int $userId): string
    {
        $state = bin2hex(random_bytes(24));
        Cache::put($this->stateKey($state), $userId, self::STATE_TTL_SECONDS);

        return 'https://github.com/login/oauth/authorize?' . http_build_query([
            'client_id'    => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'scope'        => config('services.github.scope'),
            'state'        => $state,
            'allow_signup' => 'true',
        ]);
    }

    /**
     * @throws \RuntimeException برسالة آمنة تتعرض للمستخدم
     */
    public function completeCallback(string $code, string $state): GithubConnection
    {
        $userId = Cache::pull($this->stateKey($state)); // استخدام واحد
        if (!$userId) {
            throw new \RuntimeException('expired_state');
        }

        $tokenRes = Http::acceptJson()->asForm()->timeout(15)->post('https://github.com/login/oauth/access_token', [
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri(),
        ]);
        $token = $tokenRes->json('access_token');
        if (!$tokenRes->ok() || !$token) {
            Log::warning('GitHub OAuth token exchange failed', ['status' => $tokenRes->status(), 'error' => $tokenRes->json('error')]);
            throw new \RuntimeException('token_exchange_failed');
        }

        $me = $this->api($token)->get(self::API . '/user');
        if (!$me->ok() || !$me->json('id')) {
            throw new \RuntimeException('profile_failed');
        }

        $conn = GithubConnection::firstOrNew(['user_id' => (int) $userId]);
        $conn->fill([
            'user_id'        => (int) $userId,
            'github_user_id' => (int) $me->json('id'),
            'github_login'   => (string) $me->json('login'),
            'avatar_url'     => $me->json('avatar_url'),
            'scope'          => (string) ($tokenRes->json('scope') ?? ''),
            'connected_at'   => now(),
        ]);
        $conn->setToken($token);
        $conn->save();

        Log::info('GitHub connected', ['user_id' => $userId, 'github_login' => $conn->github_login]);
        return $conn;
    }

    public function connectionFor(int $userId): ?GithubConnection
    {
        return GithubConnection::where('user_id', $userId)->first();
    }

    /** توكن المستخدم لو مربوط (بيستخدمه GithubCodeReviewService عشان يراجع مستودعاته الخاصة). */
    public function tokenFor($userId): ?string
    {
        if (!$userId) {
            return null;
        }
        return $this->connectionFor((int) $userId)?->token();
    }

    /**
     * مستودعات المستخدم (الأحدث تحديثًا أول)، بحد أقصى 300.
     * @return array<int,array<string,mixed>>
     * @throws \RuntimeException('unauthorized') لو التوكن اتلغى من GitHub
     */
    public function repositories(GithubConnection $conn): array
    {
        $token = $conn->token();
        if (!$token) {
            throw new \RuntimeException('unauthorized');
        }

        $out = [];
        for ($page = 1; $page <= 3; $page++) {
            $res = $this->api($token)->get(self::API . '/user/repos', [
                'per_page' => 100, 'page' => $page, 'sort' => 'updated',
                'affiliation' => 'owner,collaborator,organization_member',
            ]);
            if ($res->status() === 401) {
                throw new \RuntimeException('unauthorized');
            }
            if (!$res->ok()) {
                throw new \RuntimeException('github_unavailable');
            }
            $rows = $res->json() ?: [];
            foreach ($rows as $r) {
                $out[] = [
                    'id'             => $r['id'],
                    'name'           => $r['name'],
                    'full_name'      => $r['full_name'],
                    'description'    => $r['description'] ?? null,
                    'html_url'       => $r['html_url'],
                    'private'        => (bool) ($r['private'] ?? false),
                    'fork'           => (bool) ($r['fork'] ?? false),
                    'archived'       => (bool) ($r['archived'] ?? false),
                    'language'       => $r['language'] ?? null,
                    'stars'          => (int) ($r['stargazers_count'] ?? 0),
                    'default_branch' => $r['default_branch'] ?? null,
                    'updated_at'     => $r['pushed_at'] ?? ($r['updated_at'] ?? null),
                ];
            }
            if (count($rows) < 100) {
                break;
            }
        }

        $conn->last_used_at = now();
        $conn->save();
        return $out;
    }

    /**
     * مستودع واحد بصلاحيات المستخدم نفسه — بيأكد إن المستودع فعلًا بتاعه/متاح له قبل ما نربطه بمشروع.
     * @return array{name:string,full_name:string,description:?string,html_url:string}
     * @throws \RuntimeException('unauthorized'|'not_found'|'github_unavailable')
     */
    public function repository(GithubConnection $conn, string $fullName): array
    {
        if (!preg_match('#^[\w.\-]+/[\w.\-]+$#', $fullName)) {
            throw new \RuntimeException('not_found');
        }
        $token = $conn->token();
        if (!$token) {
            throw new \RuntimeException('unauthorized');
        }
        $res = $this->api($token)->get(self::API . '/repos/' . $fullName);
        if ($res->status() === 401) {
            throw new \RuntimeException('unauthorized');
        }
        if ($res->status() === 404) {
            throw new \RuntimeException('not_found');
        }
        if (!$res->ok()) {
            throw new \RuntimeException('github_unavailable');
        }
        return [
            'name'        => (string) $res->json('name'),
            'full_name'   => (string) $res->json('full_name'),
            'description' => $res->json('description'),
            'html_url'    => (string) $res->json('html_url'),
        ];
    }

    /** حذف الربط + محاولة سحب الصلاحية من GitHub (best-effort). */
    public function disconnect(GithubConnection $conn): void
    {
        $token = $conn->token();
        if ($token && $this->isConfigured()) {
            try {
                Http::withBasicAuth($this->clientId(), $this->clientSecret())
                    ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'UIP-Platform'])
                    ->timeout(10)
                    ->send('DELETE', self::API . '/applications/' . $this->clientId() . '/grant', ['json' => ['access_token' => $token]]);
            } catch (\Throwable $e) {
                Log::info('GitHub grant revoke failed (ignored)', ['error' => $e->getMessage()]);
            }
        }
        $conn->delete();
    }

    /** رابط المستودع الموحّد اللي بنخزنه في project_links (نفس الصيغة اللي isValidGithubRepoUrl بتقبلها). */
    public function canonicalUrl(string $fullName): ?string
    {
        $url = 'https://github.com/' . $fullName;
        return ProjectLink::isValidGithubRepoUrl($url) ? $url : null;
    }

    private function api(string $token)
    {
        return Http::withToken($token)->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'UIP-Platform'])->timeout(15);
    }

    private function stateKey(string $state): string
    {
        return 'github_oauth_state:' . hash('sha256', $state);
    }
}
