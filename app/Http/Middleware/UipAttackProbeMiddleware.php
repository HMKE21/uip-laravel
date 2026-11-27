<?php

namespace App\Http\Middleware;

use App\Services\SecurityDetectionService;
use App\Services\Tenancy\TenantContext;
use App\Support\AttackSignatures;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * كشف هجمات الـ API (بند 10 — الجزء 1). مراقبة بس: مبيغيّرش الرد ومبيحجبش أي طلب، والفحص كله في terminate()
 * يعني بعد ما الرد يتبعت (مفيش أثر على السرعة)، وأي فشل بيتبلع.
 *
 * بيسجّل في محرك الكشف حدثين:
 *   attack_probe — توقيع هجوم في المسار/الـ query/الـ body أو User-Agent أداة اختراق أو مسار حسّاس (.env / wp-login …).
 *   path_probe   — طلب مالوش route (404/405) = استكشاف مسارات (scanning).
 * والقواعد (Detection Rules: attack_probe_ip / path_scan) هي اللي بتنبّه أو بتحظر الـ IP حسب إعدادات الأدمن.
 *
 * Global middleware (مش جوه api group) عشان يشوف كمان الطلبات اللي مالهاش route أصلًا (/wp-login.php، /.env …).
 */
class UipAttackProbeMiddleware
{
    private const MAX_FIELDS = 300;
    private const MAX_DEPTH = 6;

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        try {
            if (!config('attack_detection.enabled', true) || $request->isMethod('OPTIONS')) {
                return;
            }
            $ip = (string) $request->ip();
            if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return;
            }
            $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 200;
            $path = '/' . ltrim($request->path(), '/');
            $matched = $request->route() !== null;
            $userId = (int) $request->attributes->get('uip_user_id') ?: null;
            $method = $request->method();

            $hit = $this->findSignature($request, $path, $matched);
            if ($hit) {
                $this->record('attack_probe', $ip, $userId, [
                    'category' => $hit['category'], 'where' => $hit['where'], 'field' => $hit['field'] ?? null,
                    'method' => $method, 'path' => mb_substr($path, 0, 160), 'status' => $status,
                    'sample' => isset($hit['sample']) ? mb_substr($hit['sample'], 0, 120) : null,
                ]);
                return;
            }

            // استكشاف: route مش موجود (404) أو method غلط (405) — مش 404 تطبيق عادي زي "السجل مش موجود".
            if (!$matched && in_array($status, [404, 405], true)
                && preg_match((string) config('attack_detection.path_probe_ignore'), $path) !== 1) {
                $this->record('path_probe', $ip, $userId, [
                    'method' => $method, 'path' => mb_substr($path, 0, 160), 'status' => $status,
                ]);
            }
        } catch (\Throwable) {
            // مراقبة بس
        }
    }

    /** @return array{category:string,where:string,field?:string,sample?:string}|null */
    private function findSignature(Request $request, string $path, bool $matched): ?array
    {
        if (!$matched && AttackSignatures::sensitivePath($path)) {
            return ['category' => 'sensitive_path', 'where' => 'path', 'sample' => $path];
        }
        if ($tool = AttackSignatures::scannerUserAgent($request->userAgent())) {
            return ['category' => 'scanner_tool', 'where' => 'user_agent', 'sample' => $tool];
        }
        if ($cat = AttackSignatures::inspect($path)) {
            return ['category' => $cat, 'where' => 'path', 'sample' => $path];
        }
        $query = (string) $request->server->get('QUERY_STRING', '');
        if ($query !== '') {
            if ($cat = AttackSignatures::inspect($query)) {
                return ['category' => $cat, 'where' => 'query', 'sample' => $query];
            }
        }

        if (!config('attack_detection.scan_body', true) || $request->isMethodSafe()) {
            return null;
        }
        $trimmed = trim($request->path(), '/');
        foreach ((array) config('attack_detection.body_skip_paths', []) as $pattern) {
            if (fnmatch($pattern, $trimmed)) {
                return null;
            }
        }
        $count = 0;
        return $this->scanValue($request->except(array_keys($request->allFiles())), '', 0, $count);
    }

    /** @return array{category:string,where:string,field?:string,sample?:string}|null */
    private function scanValue($value, string $key, int $depth, int &$count): ?array
    {
        if ($depth > self::MAX_DEPTH || $count > self::MAX_FIELDS) {
            return null;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $name = is_string($k) ? $k : $key;
                if (is_string($k) && preg_match((string) config('attack_detection.skip_fields'), $k) === 1) {
                    continue;
                }
                if (is_string($k) && ($cat = AttackSignatures::inspect($k))) { // مفتاح الحقل نفسه ملغّم
                    return ['category' => $cat, 'where' => 'body', 'field' => mb_substr($k, 0, 60), 'sample' => $k];
                }
                if ($hit = $this->scanValue($v, $name, $depth + 1, $count)) {
                    return $hit;
                }
            }
            return null;
        }
        if (!is_string($value) || strlen($value) < 4) {
            return null;
        }
        $count++;
        if (strlen($value) > 2000 && preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', substr($value, 0, 4000)) === 1) {
            return null; // blob base64
        }
        if ($cat = AttackSignatures::inspect($value)) {
            return ['category' => $cat, 'where' => 'body', 'field' => mb_substr($key, 0, 60), 'sample' => $value];
        }
        return null;
    }

    /** حد أقصى للأحداث لكل IP في الدقيقة — المهاجم مايقدرش يملّي الجدول. */
    private function record(string $event, string $ip, ?int $userId, array $meta): void
    {
        $max = max(1, (int) config('attack_detection.max_events_per_ip_minute', 30));
        $key = 'atk:rate:' . TenantContext::ns() . $ip . ':' . intdiv(time(), 60);
        Cache::add($key, 0, 90);
        if ((int) Cache::increment($key) > $max) {
            return;
        }
        app(SecurityDetectionService::class)->record($event, ['ip' => $ip, 'user_id' => $userId, 'meta' => $meta]);
    }
}
