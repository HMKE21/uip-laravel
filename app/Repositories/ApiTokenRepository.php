<?php

namespace App\Repositories;

use App\Models\ApiToken;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/ApiTokenRepository.php القديمة — بند 25
 * batch 4 (AdminMobileApiController). إدارة الـ bearer tokens طويلة
 * العمر الصادرة للعميل الموبايل المستقبلي.
 */
class ApiTokenRepository
{
    /** كل التوكنز (فعالة + ملغاة) مع اسم الأدمن اللي أصدرها، الأحدث الأول. */
    public function allWithCreator(): array
    {
        return DB::table('api_tokens as t')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->select('t.id', 't.name', 't.last_used_at', 't.revoked_at', 't.created_at', 'u.full_name as created_by_name')
            ->orderByDesc('t.created_at')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    public function create(array $data): ApiToken
    {
        return ApiToken::create($data);
    }

    public function revoke($id): bool
    {
        $token = ApiToken::find($id);
        if (!$token || $token->revoked_at) {
            return false;
        }
        $token->fill(['revoked_at' => now()]);
        $token->save();
        return true;
    }

    /** حذف نهائي لتوكن **ملغي** بس (التوكن الفعال لازم يتلغي الأول). false = مش موجود أو لسه فعال. */
    public function deleteRevoked($id): bool
    {
        $token = ApiToken::find($id);
        if (!$token || !$token->revoked_at) {
            return false;
        }
        DB::table('api_token_usages')->where('api_token_id', (int) $id)->delete();
        return (bool) $token->delete();
    }

    public function activeCount(): int
    {
        return ApiToken::whereNull('revoked_at')->count();
    }

    /** بتدور على توكن فعال بالـ hash بتاع الـ raw value — لميدلوير مستقبلية للـ API auth. */
    public function findActiveByRawToken(string $rawToken): ?ApiToken
    {
        return ApiToken::where('token_hash', hash('sha256', $rawToken))->whereNull('revoked_at')->first();
    }

    // -- تفاصيل التوكن + سجل استخدامه (api_token_usages) --------------------------------------

    public function findWithCreator($id): ?array
    {
        $row = DB::table('api_tokens as t')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->where('t.id', (int) $id)
            ->select('t.id', 't.name', 't.last_used_at', 't.revoked_at', 't.created_at', 'u.full_name as created_by_name', 'u.email as created_by_email')
            ->first();
        return $row ? (array) $row : null;
    }

    private static ?bool $hasDiag = null;

    /** هل migration التشخيص (source/meta/...) اتعملت؟ */
    private static function hasDiag(): bool
    {
        return self::$hasDiag ??= \Illuminate\Support\Facades\Schema::hasColumn('api_token_usages', 'source');
    }

    /** شرط "فشل": status >= 400 أو بلاغ من التطبيق (source=client). */
    private static function errExpr(string $a = ''): string
    {
        $p = $a !== '' ? $a . '.' : '';
        return self::hasDiag() ? "({$p}status >= 400 OR {$p}source = 'client')" : "({$p}status >= 400)";
    }

    /** @return array<string,mixed> ملخص كامل لاستخدام التوكن. */
    public function usageSummary(int $id): array
    {
        // صفوف source=client = بلاغات من الموبايل (طلبات ماوصلتش للسيرفر): بعيدة عن عدّادات الطلبات.
        $base = fn () => DB::table('api_token_usages')->where('api_token_id', $id)
            ->when(self::hasDiag(), fn ($q) => $q->where('source', 'server'));
        $clientFailures = self::hasDiag()
            ? DB::table('api_token_usages')->where('api_token_id', $id)->where('source', 'client')->count() : 0;

        $t = (array) $base()->selectRaw(
            "COUNT(*) AS total, MIN(created_at) AS first_seen, MAX(created_at) AS last_seen,
             COUNT(DISTINCT user_id) AS users, COUNT(DISTINCT ip) AS ips,
             COUNT(DISTINCT COALESCE(NULLIF(device_id, ''), user_agent)) AS devices,
             SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS errors,
             SUM(CASE WHEN outcome = 'revoked' THEN 1 ELSE 0 END) AS rejected,
             SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END) AS anonymous"
        )->first();

        $since = fn (int $hours) => $base()->where('created_at', '>=', now()->subHours($hours))->count();

        $byDay = $base()->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS n')->groupBy('day')->orderBy('day')->get()
            ->pluck('n', 'day')->all();

        $endpoints = $base()->selectRaw("method, COALESCE(route, path) AS endpoint, COUNT(*) AS n, MAX(created_at) AS last_at,
                SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS errors")
            ->groupBy('method', DB::raw('COALESCE(route, path)'))->orderByDesc('n')->limit(25)->get()->map(fn ($r) => (array) $r)->all();

        $statuses = $base()->selectRaw('status, COUNT(*) AS n, MAX(created_at) AS last_at')->groupBy('status')->orderByDesc('n')->get()->map(fn ($r) => (array) $r)->all();

        $users = DB::table('api_token_usages as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.api_token_id', $id)
            ->when(self::hasDiag(), fn ($q) => $q->where('a.source', 'server'))
            ->selectRaw("u.id, u.full_name, u.email, COUNT(*) AS n, MIN(a.created_at) AS first_at, MAX(a.created_at) AS last_at,
                SUM(CASE WHEN a.status >= 400 THEN 1 ELSE 0 END) AS errors,
                (SELECT r.slug FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id ORDER BY ur.assigned_at ASC LIMIT 1) AS role")
            ->groupBy('u.id', 'u.full_name', 'u.email')->orderByDesc('n')->limit(100)->get()->map(fn ($r) => (array) $r)->all();

        $ips = $base()->whereNotNull('ip')->selectRaw('ip, COUNT(*) AS n, MIN(created_at) AS first_at, MAX(created_at) AS last_at, COUNT(DISTINCT user_id) AS users, SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS errors')
            ->groupBy('ip')->orderByDesc('n')->limit(50)->get()->map(fn ($r) => (array) $r)->all();

        // نفس تعريف عدّاد "الأجهزة": device_id لو موجود، وإلا الـ user_agent.
        $devices = $base()->selectRaw("MAX(user_agent) AS ua, MAX(NULLIF(device_id, '')) AS dev, MAX(client) AS client, COUNT(*) AS n,
                MIN(created_at) AS first_at, MAX(created_at) AS last_at, COUNT(DISTINCT user_id) AS users,
                SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS errors")
            ->groupBy(DB::raw("COALESCE(NULLIF(device_id, ''), user_agent)"))->orderByDesc('n')->limit(50)->get()->map(fn ($r) => (array) $r)->all();

        return [
            'totals'    => array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $t) + ['client_failures' => $clientFailures],
            'retention_days' => max(1, (int) config('site.app_token_log_days', 60)),
            'last_24h'  => $since(24),
            'last_7d'   => $since(24 * 7),
            'by_day'    => $byDay,
            'endpoints' => $endpoints,
            'statuses'  => $statuses,
            'users'     => $users,
            'ips'       => $ips,
            'devices'   => $devices,
        ];
    }

    /** @return array{rows:array,total:int} صفحة من سجل الطلبات (الأحدث أولًا) مع فلاتر. */
    public function usageLog(int $id, int $page, int $perPage, array $f = []): array
    {
        $q = DB::table('api_token_usages as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.api_token_id', $id);

        if (!empty($f['user_id'])) {
            $q->where('a.user_id', (int) $f['user_id']);
        }
        if (!empty($f['ip'])) {
            $q->where('a.ip', $f['ip']);
        }
        if (!empty($f['status_code'])) {
            $q->where('a.status', (int) $f['status_code']);
        }
        if (!empty($f['endpoint'])) {
            $q->whereRaw('COALESCE(a.route, a.path) = ?', [(string) $f['endpoint']]);
        }
        if (!empty($f['device'])) {
            $q->whereRaw("COALESCE(NULLIF(a.device_id, ''), a.user_agent) = ?", [(string) $f['device']]);
        }
        if (!empty($f['method'])) {
            $q->where('a.method', strtoupper((string) $f['method']));
        }
        $diag = self::hasDiag();
        if (($f['status'] ?? '') === 'error') {
            $q->whereRaw(self::errExpr('a'));
        } elseif (($f['status'] ?? '') === 'ok') {
            $q->where('a.status', '<', 400);
            if ($diag) {
                $q->where('a.source', 'server');
            }
        } elseif (($f['status'] ?? '') === 'rejected') {
            $q->where('a.outcome', 'revoked');
        } elseif ($diag && ($f['status'] ?? '') === 'client') {
            $q->where('a.source', 'client');
        }
        if (($f['kind'] ?? '') !== '' && $diag) {
            $q->where('a.error_code', (string) $f['kind']);
        }
        if (($needle = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $needle) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('a.path', 'like', $like)->orWhere('a.ip', 'like', $like)->orWhere('a.error_message', 'like', $like)->orWhere('a.error_code', 'like', $like)
                  ->orWhere('u.full_name', 'like', $like)->orWhere('u.email', 'like', $like);
            });
        }

        $total = (clone $q)->count();
        $cols = ['a.id', 'a.method', 'a.path', 'a.route', 'a.status', 'a.outcome', 'a.ip', 'a.user_agent', 'a.client', 'a.device_id', 'a.created_at',
                'a.error_code', 'a.error_message', 'a.error_detail',
                'a.user_id', 'u.full_name AS user_name', 'u.email AS user_email'];
        if ($diag) {
            array_push($cols, 'a.source', 'a.request_bytes', 'a.duration_ms', 'a.content_type', 'a.meta');
        }
        $rows = $q->select($cols)
            ->orderByDesc('a.id')->offset(max(0, ($page - 1) * $perPage))->limit($perPage)->get()->map(fn ($r) => (array) $r)->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Requests per day for the "Requests per day" chart, with the same filters as the request log.
     * @param array{status?:string,user_id?:mixed,device?:string,endpoint?:string,ip?:string} $f
     * @return array<string,int> 'YYYY-MM-DD' => count (days with no requests are left out)
     */
    public function dailyCounts(int $id, int $days, array $f = []): array
    {
        $q = DB::table('api_token_usages as a')
            ->where('a.api_token_id', $id)
            ->where('a.created_at', '>=', now()->subDays(max(1, $days) - 1)->startOfDay());

        if (!empty($f['user_id'])) {
            $q->where('a.user_id', (int) $f['user_id']);
        }
        if (!empty($f['ip'])) {
            $q->where('a.ip', $f['ip']);
        }
        if (!empty($f['endpoint'])) {
            $q->whereRaw('COALESCE(a.route, a.path) = ?', [(string) $f['endpoint']]);
        }
        if (!empty($f['device'])) {
            $q->whereRaw("COALESCE(NULLIF(a.device_id, ''), a.user_agent) = ?", [(string) $f['device']]);
        }
        if (self::hasDiag()) {
            $q->where('a.source', ($f['status'] ?? '') === 'client' ? 'client' : 'server');
        }
        if (($f['status'] ?? '') === 'error') {
            $q->where('a.status', '>=', 400);
        } elseif (($f['status'] ?? '') === 'ok') {
            $q->where('a.status', '<', 400);
        } elseif (($f['status'] ?? '') === 'rejected') {
            $q->where('a.outcome', 'revoked');
        }

        return $q->selectRaw('DATE(a.created_at) AS day, COUNT(*) AS n')->groupBy('day')->orderBy('day')->get()
            ->pluck('n', 'day')->map(fn ($n) => (int) $n)->all();
    }
}
