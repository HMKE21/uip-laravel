<?php

namespace App\Http\Middleware;

use App\Services\SecurityDetectionService;
use Closure;
use Illuminate\Http\Request;

/**
 * مراقبة الداتا — يغذّي محرك الكشف بأحداث: data_export / data_delete / data_bulk_update / config_change
 * (القواعد: mass_export / mass_delete / mass_modify / sensitive_change). الإعدادات في config/data_guard.php.
 *
 * بيراقب بس: مبيغيّرش الرد ومبيحجبش حاجة، والتسجيل بيتم بعد إرسال الرد (terminating)، وأي فشل بيتبلع.
 * بيعدّ بس طلب ناجح (2xx) من مستخدم مسجّل دخول.
 */
class UipDataGuardMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        try {
            if (!config('data_guard.enabled', true)) {
                return $response;
            }
            $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 200;
            if ($status < 200 || $status >= 300) {
                return $response;
            }
            $userId = (int) $request->attributes->get('uip_user_id');
            if (!$userId) {
                return $response;
            }
            $route = $request->route();
            $uri = $route && method_exists($route, 'uri') ? (string) $route->uri() : '';
            if ($uri === '') {
                return $response;
            }

            $classified = $this->classify($request, $uri);
            if ($classified === null) {
                return $response;
            }

            [$event, $amount, $meta] = $classified;
            $payload = [
                'user_id' => $userId,
                'ip'      => $request->ip(),
                'amount'  => $amount,
                'meta'    => $meta + ['route' => $uri, 'method' => $request->method()],
            ];
            app()->terminating(function () use ($event, $payload) {
                try {
                    app(SecurityDetectionService::class)->record($event, $payload);
                } catch (\Throwable) {
                }
            });
        } catch (\Throwable) {
            // مراقبة بس
        }

        return $response;
    }

    /** @return array{0:string,1:int,2:array<string,mixed>}|null */
    private function classify(Request $request, string $uri): ?array
    {
        $method = $request->method();
        $path = '/' . ltrim($uri, '/');
        $write = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        // 1) تغيير صلاحيات/إعدادات حساسة (قبل الباقي: حذف دور = config_change مش data_delete).
        if ($write && !str_ends_with($uri, '/preview') && $this->matchesAny($uri, (array) config('data_guard.config_change', []))) {
            $meta = ['target' => $this->target($request)];
            return ['config_change', 1, array_filter($meta, fn ($v) => $v !== null)];
        }

        if ($this->matchesAny($uri, (array) config('data_guard.ignore', []))) {
            return null;
        }

        // 2) تصدير.
        if (in_array($method, ['GET', 'POST'], true)) {
            $isExport = (bool) preg_match((string) config('data_guard.export_pattern'), $path)
                || ($method === 'POST' && preg_match((string) config('data_guard.export_create_pattern'), $path));
            if ($isExport) {
                return ['data_export', 1, []];
            }
        }

        if (!$write) {
            return null;
        }

        // 3) حذف/تفريغ جماعي (POST/PATCH/DELETE على pattern الحذف الجماعي).
        if (preg_match((string) config('data_guard.bulk_delete_pattern'), $path)) {
            return ['data_delete', $this->countItems($request, (int) config('data_guard.sweep_weight', 25)), ['bulk' => true]];
        }

        // 4) حذف عادي (DELETE) = 1.
        if ($method === 'DELETE') {
            return ['data_delete', 1, []];
        }

        // 5) تعديل جماعي / استيراد.
        if (preg_match((string) config('data_guard.bulk_update_pattern'), $path)) {
            return ['data_bulk_update', $this->countItems($request, (int) config('data_guard.bulk_default_weight', 5)), ['bulk' => true]];
        }

        return null;
    }

    /** عدد العناصر في الطلب (أول مفتاح قايمة مطابق)، أو $fallback لو مفيش. */
    private function countItems(Request $request, int $fallback): int
    {
        $max = (int) config('data_guard.max_amount', 100000);
        try {
            $input = $request->input();
            foreach ((array) config('data_guard.bulk_id_keys', []) as $key) {
                if (isset($input[$key]) && is_array($input[$key])) {
                    return max(1, min($max, count($input[$key])));
                }
            }
        } catch (\Throwable) {
        }
        return max(1, min($max, $fallback));
    }

    /** أول route parameter بسيط (id / slug) عشان التنبيه يقول "اتغيّر إيه". */
    private function target(Request $request): ?string
    {
        try {
            $params = $request->route()?->parameters() ?? [];
            foreach (['slug', 'id', 'role', 'userId'] as $k) {
                if (isset($params[$k]) && is_scalar($params[$k])) {
                    return mb_substr((string) $params[$k], 0, 80);
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }

    /** @param string[] $patterns */
    private function matchesAny(string $uri, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (fnmatch($p, $uri)) {
                return true;
            }
        }
        return false;
    }
}
