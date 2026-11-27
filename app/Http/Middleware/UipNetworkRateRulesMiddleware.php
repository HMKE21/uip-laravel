<?php

namespace App\Http\Middleware;

use App\Repositories\BlockedIpRepository;
use App\Services\NetworkControlService;
use App\Services\Tenancy\TenantContext;
use App\Services\UipJwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * قواعد حد المعدل لكل مسار (Security > Network control > Rate limits) — فوق الحد العام (UipRateLimitMiddleware).
 *
 * كل قاعدة: نمط مسار (Request::is — مثال api/v1/auth/*) + method اختياري + حد في الدقيقة + نطاق (ip | user).
 * تجاوز الحد => 429 مع Retry-After. لو الإجراء "block" الـ IP نفسه بيتحظر لمدة (blocked_ips، source=rate) — لازم يكون
 * النطاق ip عشان نحظر عنوان فعلاً. عناوين الموثوقين مستثناة. أي خطأ (جدول/كاش) => الطلب يعدّي، عمره ما يوقف الـ API.
 *
 * العدّاد: fixed-window لكل دقيقة بـ Cache::add + increment (زي الحد العام). عدّاد الانتهاكات اليومي بيتعرض في الصفحة.
 */
class UipNetworkRateRulesMiddleware
{
    public function __construct(
        private NetworkControlService $network,
        private BlockedIpRepository $blockedIps
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        try {
            $rules = $this->network->activeRateRules();
            if (!$rules) {
                return $next($request);
            }
            $ip = (string) $request->ip();
            if ($this->network->isTrusted($ip)) {
                return $next($request);
            }

            foreach ($rules as $rule) {
                if (!$this->applies($request, $rule)) {
                    continue;
                }
                $actor = ($rule['scope'] ?? 'ip') === 'user' ? $this->userKey($request, $ip) : 'ip_' . $ip;
                $window = (int) floor(time() / 60);
                $key = 'netrule:' . TenantContext::ns() . $rule['id'] . ':' . sha1($actor) . ':' . $window;
                Cache::add($key, 0, 65);
                $count = (int) Cache::increment($key);
                if ($count <= (int) $rule['limit_per_min']) {
                    continue;
                }

                // انتهاك: عدّاد يومي للعرض + (اختياري) حظر الـ IP.
                $day = 'netrulehits:' . TenantContext::ns() . $rule['id'] . ':' . date('Ymd');
                Cache::add($day, 0, 90000);
                Cache::increment($day);

                if (($rule['action'] ?? 'throttle') === 'block' && $this->isPublicIp($ip) && $count === (int) $rule['limit_per_min'] + 1) {
                    $mins = (int) ($rule['block_minutes'] ?? 0);
                    $this->blockedIps->blockEntry(
                        $ip,
                        mb_substr('Rate rule: ' . $rule['name'], 0, 255),
                        null,
                        $mins > 0 ? now()->addMinutes($mins) : null,
                        'rate'
                    );
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests.',
                    'data'    => null,
                    'errors'  => ['code' => 'rate_rule'],
                    'meta'    => (object) [],
                ], 429)->header('Retry-After', (string) (60 - (time() % 60)));
            }
        } catch (\Throwable $e) {
            // كاش/DB واقعين: ما نوقفش الطلب.
        }

        return $next($request);
    }

    /** @param array<string,mixed> $rule */
    private function applies(Request $request, array $rule): bool
    {
        $method = strtoupper((string) ($rule['method'] ?? 'ANY'));
        if ($method !== 'ANY' && $request->method() !== $method) {
            return false;
        }
        $pattern = ltrim((string) $rule['path_pattern'], '/');
        return $pattern !== '' && $request->is($pattern);
    }

    /** مبنحظرش عناوين محلية/خاصة (localhost، شبكة داخلية) عشان ما نقفلش المنصة على نفسها. */
    private function isPublicIp(string $ip): bool
    {
        return $ip !== '' && (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function userKey(Request $request, string $ip): string
    {
        $header = (string) $request->header('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            $claims = UipJwtService::decodeAccess(substr($header, 7));
            if ($claims && !empty($claims['sub'])) {
                return 'user_' . $claims['sub'];
            }
        }
        return 'ip_' . $ip;
    }
}
