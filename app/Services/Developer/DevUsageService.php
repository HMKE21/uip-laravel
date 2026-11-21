<?php

namespace App\Services\Developer;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate Limit Dashboard: استهلاك الـ Developer API لمستخدم واحد.
 *  - "دلوقتي": عدّاد النافذة الحالية (60 ثانية) من نفس RateLimiter اللي بيطبّق الحد، فالرقم هو اللي الـ 429 هتتحسب عليه فعلاً.
 *  - "في الفترة": تجميعات من dev_request_logs (طلبات الـ API والـ sandbox بس، مش OAuth ولا webhook).
 * الـ 429 بتتسجّل صف واحد في الدقيقة لكل مفتاح/توكن (DeveloperAuthService::noteThrottled) عشان الفلود ميكتبش في الـ DB،
 * فـ throttled = عدد الدقايق اللي اتحجب فيها، مش عدد الطلبات المحجوبة بالظبط.
 */
class DevUsageService
{
    /** range => [مدة بالثواني, حجم الـ bucket, تنسيق bucket لـ SQL (mysql), (sqlite)] */
    private const RANGES = [
        '1h' => [3600, 60, '%Y-%m-%d %H:%i:00', '%Y-%m-%d %H:%M:00'],
        '24h' => [86400, 3600, '%Y-%m-%d %H:00:00', '%Y-%m-%d %H:00:00'],
        '7d' => [604800, 86400, '%Y-%m-%d 00:00:00', '%Y-%m-%d 00:00:00'],
    ];

    public function __construct(private DeveloperAppService $apps)
    {
    }

    public static function ranges(): array
    {
        return array_keys(self::RANGES);
    }

    /** الحد الفعلي لمفتاح: حد التطبيق (أو الافتراضي) ثم سقف سياسة الجامعة — نفس منطق DeveloperAuthService. */
    public static function effectiveLimit(?int $appLimit, ?int $policyCap): int
    {
        $limit = $appLimit && $appLimit > 0 ? $appLimit : (int) config('developer.limits.rate_per_min', 300);
        if ($policyCap) {
            $limit = min($limit, $policyCap);
        }
        return max(1, $limit);
    }

    /** الجزء الخاص بالـ SQL: تعبير تجميع الوقت حسب نوع الـ DB. */
    private function bucketExpr(string $range): string
    {
        [, , $mysql, $sqlite] = self::RANGES[$range];
        return DB::getDriverName() === 'sqlite' ? "strftime('{$sqlite}', created_at)" : "DATE_FORMAT(created_at, '{$mysql}')";
    }

    /** @return array<string,mixed> */
    /** @param ?int $tenantId null = الاستهلاك الشخصي (من غير الـ Workspaces). ومع tenantId = استهلاك الـ Workspace كله (كل أعضاؤه). */
    public function report(int $userId, string $range = '24h', ?int $tenantId = null): array
    {
        $range = isset(self::RANGES[$range]) ? $range : '24h';
        [$secs, $step] = self::RANGES[$range];
        $now = Carbon::now('UTC');
        $from = $now->copy()->subSeconds($secs)->startOfMinute();
        $policy = $this->apps->policyFor($userId);
        $cap = !empty($policy['rate_limit_per_min']) ? (int) $policy['rate_limit_per_min'] : null;

        $mine = fn ($q, string $uc, string $tc) => $tenantId ? $q->where($tc, $tenantId) : $q->where($uc, $userId)->whereNull($tc);
        $base = fn () => $mine(DB::table('dev_request_logs'), 'user_id', 'tenant_id')->whereIn('kind', ['api', 'sandbox'])->where('created_at', '>=', $from->toDateTimeString());
        $agg = 'COUNT(*) AS requests, SUM(CASE WHEN status = 429 THEN 1 ELSE 0 END) AS throttled, '
            . 'SUM(CASE WHEN status >= 400 AND status <> 429 THEN 1 ELSE 0 END) AS errors, AVG(duration_ms) AS avg_ms';

        $tot = $base()->selectRaw($agg)->first();
        $totals = $this->shape($tot);

        // السلسلة الزمنية: كل bucket فاضي بيتملا بصفر عشان الرسم متقطعش.
        $rows = $base()->selectRaw($this->bucketExpr($range) . ' AS b, ' . $agg)->groupBy('b')->get()->keyBy('b');
        $series = [];
        for ($t = $this->floorTo($from, $step); $t->lte($now); $t = $t->copy()->addSeconds($step)) {
            $r = $rows[$t->format('Y-m-d H:i:s')] ?? null;
            $series[] = ['t' => $t->toIso8601String()] + array_intersect_key($this->shape($r), array_flip(['requests', 'errors', 'throttled']));
        }

        // المفاتيح: كل مفاتيح المستخدم (حتى الملغية لو ليها استهلاك في الفترة).
        $keys = $mine(DB::table('dev_api_keys as k')->leftJoin('dev_apps as a', 'a.id', '=', 'k.app_id'), 'k.user_id', 'k.tenant_id')
            ->get(['k.id', 'k.name', 'k.prefix', 'k.mode', 'k.app_id', 'k.revoked_at', 'k.expires_at', 'k.last_used_at', 'a.name as app_name', 'a.rate_limit_per_min as app_limit']);
        $perKey = $base()->whereNotNull('api_key_id')->selectRaw('api_key_id AS id, ' . $agg)->groupBy('api_key_id')->get()->keyBy('id');
        $keyRows = [];
        foreach ($keys as $k) {
            $s = $this->shape($perKey[$k->id] ?? null);
            $revoked = $k->revoked_at !== null;
            if ($revoked && $s['requests'] === 0) {
                continue;
            }
            $limit = self::effectiveLimit($k->app_limit ? (int) $k->app_limit : null, $cap);
            $used = $revoked ? 0 : (int) RateLimiter::attempts('dev:rl:key:' . $k->id);
            $keyRows[] = [
                'id' => (int) $k->id, 'name' => $k->name, 'prefix' => $k->prefix, 'mode' => $k->mode, 'app_id' => $k->app_id ? (int) $k->app_id : null, 'app_name' => $k->app_name,
                'revoked' => $revoked, 'limit_per_min' => $limit, 'used_now' => min($used, $limit), 'pct_now' => (int) min(100, round($used / $limit * 100)),
                'last_used_at' => DevTokens::iso($k->last_used_at),
            ] + $s;
        }
        usort($keyRows, fn ($a, $b) => [$b['requests'], $a['id']] <=> [$a['requests'], $b['id']]);

        // التطبيقات: تجميع طلبات المفاتيح + توكنز OAuth على مستوى التطبيق.
        $apps = $mine(DB::table('dev_apps'), 'owner_user_id', 'tenant_id')->get(['id', 'name', 'status', 'rate_limit_per_min']);
        $perApp = $base()->whereNotNull('app_id')->selectRaw('app_id AS id, ' . $agg)->groupBy('app_id')->get()->keyBy('id');
        $appRows = $apps->map(fn ($a) => [
            'id' => (int) $a->id, 'name' => $a->name, 'status' => $a->status, 'limit_per_min' => self::effectiveLimit($a->rate_limit_per_min ? (int) $a->rate_limit_per_min : null, $cap),
            'custom_limit' => $a->rate_limit_per_min ? (int) $a->rate_limit_per_min : null,
        ] + $this->shape($perApp[$a->id] ?? null))->sortByDesc('requests')->values()->all();

        $paths = $base()->selectRaw('path, COUNT(*) AS n')->whereNotNull('path')->groupBy('path')->orderByDesc('n')->limit(5)->get()
            ->map(fn ($r) => ['path' => $r->path, 'requests' => (int) $r->n])->all();

        return [
            'range' => $range, 'from' => $from->toIso8601String(), 'to' => $now->toIso8601String(), 'step_seconds' => $step,
            'limits' => ['default_per_min' => (int) config('developer.limits.rate_per_min', 300), 'policy_cap_per_min' => $cap],
            'totals' => $totals, 'series' => $series, 'keys' => $keyRows, 'apps' => $appRows, 'top_paths' => $paths,
        ];
    }

    private function floorTo(Carbon $t, int $step): Carbon
    {
        $c = $t->copy();
        return $step >= 86400 ? $c->startOfDay() : ($step >= 3600 ? $c->startOfHour() : $c->startOfMinute());
    }

    /** @return array{requests:int,errors:int,throttled:int,avg_ms:?int} */
    private function shape($row): array
    {
        return [
            'requests' => (int) ($row->requests ?? 0), 'errors' => (int) ($row->errors ?? 0), 'throttled' => (int) ($row->throttled ?? 0),
            'avg_ms' => isset($row->avg_ms) && $row->avg_ms !== null ? (int) round((float) $row->avg_ms) : null,
        ];
    }
}
