<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user activity timeline: write side (sanitised inserts, used by the browser-tracker endpoint and the server middleware)
 * and read side (search / summary / sessions / export for Admin → User details → Activity).
 *
 * Privacy rules enforced here, not left to callers: no request bodies, no typed values, no tokens/passwords in URLs,
 * everything length-capped.
 */
class UserActivityService
{
    public const TABLE = 'user_activity_events';

    public const TYPES = ['page_view', 'click', 'rage_click', 'form_submit', 'api_write', 'api_error', 'network_error', 'js_error', 'auth', 'custom'];
    public const SEVERITIES = ['info', 'warning', 'error'];

    /** Query-string keys whose values are replaced by «…» before a URL is stored. */
    private const SECRET_KEYS = ['token', 'access_token', 'refresh_token', 'password', 'pass', 'pwd', 'secret', 'code', 'otp', 'key', 'api_key', 'signature', 'sig', 'auth', 'authorization', 'jwt', 'session'];

    private static ?bool $tableExists = null;

    public static function enabled(): bool
    {
        if (!config('activity.enabled', true)) {
            return false;
        }
        if (self::$tableExists === null) {
            try {
                self::$tableExists = Schema::hasTable(self::TABLE);
            } catch (\Throwable $e) {
                self::$tableExists = false;
            }
        }

        return self::$tableExists;
    }

    // ---------------------------------------------------------------- write

    /** Inserts one event. Never throws. @param array<string,mixed> $e */
    public function record(int $userId, array $e): bool
    {
        if ($userId <= 0 || !self::enabled()) {
            return false;
        }
        try {
            $row = $this->normalise($userId, $e);
            if ($row === null) {
                return false;
            }
            DB::table(self::TABLE)->insert($row);

            return true;
        } catch (\Throwable $ex) {
            Log::warning('User activity write failed', ['error' => $ex->getMessage()]);

            return false;
        }
    }

    /** Inserts a browser batch (already validated for size by the controller). Returns how many rows were stored. */
    public function recordBatch(int $userId, array $events, array $ctx): int
    {
        if ($userId <= 0 || !self::enabled()) {
            return 0;
        }
        $rows = [];
        foreach (array_slice($events, 0, (int) config('activity.max_batch', 60)) as $e) {
            if (!is_array($e)) {
                continue;
            }
            // The browser may only send browser-side kinds; server kinds come from the middleware.
            $type = (string) ($e['type'] ?? '');
            if (!in_array($type, ['page_view', 'click', 'rage_click', 'form_submit', 'api_error', 'network_error', 'js_error', 'auth', 'custom'], true)) {
                continue;
            }
            $e['source'] = 'client';
            $row = $this->normalise($userId, $e + $ctx);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        if (!$rows) {
            return 0;
        }
        try {
            DB::table(self::TABLE)->insert($rows);

            return count($rows);
        } catch (\Throwable $ex) {
            Log::warning('User activity batch failed', ['error' => $ex->getMessage()]);

            return 0;
        }
    }

    /** @return array<string,mixed>|null */
    private function normalise(int $userId, array $e): ?array
    {
        $type = (string) ($e['type'] ?? 'custom');
        if (!in_array($type, self::TYPES, true)) {
            $type = 'custom';
        }
        $severity = (string) ($e['severity'] ?? 'info');
        if (!in_array($severity, self::SEVERITIES, true)) {
            $severity = 'info';
        }
        $title = $this->clip($e['title'] ?? '', 255);
        if ($title === '') {
            return null;
        }

        $at = null;
        if (isset($e['ts']) && is_numeric($e['ts'])) {
            // Browser clock: trust it only inside a sane window (clock drift / replayed old queue).
            $ms = (int) $e['ts'];
            $now = (int) round(microtime(true) * 1000);
            if ($ms <= $now + 60000 && $ms >= $now - 86400000) {
                $at = Carbon::createFromTimestampMs($ms);
            }
        }
        $at = $at ?: Carbon::now();

        $ua = (string) ($e['user_agent'] ?? '');

        return [
            'user_id'     => $userId,
            'session_id'  => $this->clip($e['session_id'] ?? null, 64) ?: null,
            'occurred_at' => $at->format('Y-m-d H:i:s.v'),
            'type'        => $type,
            'severity'    => $severity,
            'source'      => ($e['source'] ?? 'client') === 'server' ? 'server' : 'client',
            'title'       => $title,
            'path'        => $this->cleanUrl($e['path'] ?? null, 500),
            'target'      => $this->clip($e['target'] ?? null, 255) ?: null,
            'method'      => $this->clip(strtoupper((string) ($e['method'] ?? '')), 8) ?: null,
            'endpoint'    => $this->clip($e['endpoint'] ?? null, 255) ?: null,
            'url'         => $this->cleanUrl($e['url'] ?? null, 500),
            'status'      => isset($e['status']) && is_numeric($e['status']) ? max(0, min(65535, (int) $e['status'])) : null,
            'duration_ms' => isset($e['duration_ms']) && is_numeric($e['duration_ms']) ? max(0, min(4294967295, (int) $e['duration_ms'])) : null,
            'message'     => ($m = $this->clip($e['message'] ?? null, 2000)) !== '' ? $m : null,
            'ip'          => $this->clip($e['ip'] ?? null, 45) ?: null,
            'device'      => $this->clip($e['device'] ?? self::deviceOf($ua), 16) ?: null,
            'browser'     => $this->clip($e['browser'] ?? self::browserOf($ua), 40) ?: null,
            'user_agent'  => $this->clip($ua, 255) ?: null,
            'details'     => $this->encodeDetails($e['details'] ?? null),
        ];
    }

    private function clip($v, int $max): string
    {
        if ($v === null || is_array($v) || is_object($v)) {
            return '';
        }
        $s = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v));

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    /** Keeps the path and the query KEYS; secret-looking values are masked. */
    private function cleanUrl($url, int $max): ?string
    {
        $s = $this->clip($url, 2000);
        if ($s === '') {
            return null;
        }
        $s = preg_replace('#^https?://[^/]+#i', '', $s) ?? $s; // origin is noise
        $parts = explode('?', explode('#', $s, 2)[0], 2);
        $out = $parts[0];
        if (isset($parts[1]) && $parts[1] !== '') {
            $pairs = [];
            foreach (explode('&', $parts[1]) as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $pairs[] = in_array(strtolower(urldecode($k)), self::SECRET_KEYS, true) ? $k . '=…' : $pair;
            }
            $out .= '?' . implode('&', $pairs);
        }

        return mb_substr($out, 0, $max);
    }

    private function encodeDetails($d): ?string
    {
        if (!is_array($d) || !$d) {
            return null;
        }
        $d = $this->scrub($d, 0);
        $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false || $json === '[]' || $json === '{}') {
            return null;
        }

        return strlen($json) > 6000 ? json_encode(['truncated' => true, 'preview' => mb_substr($json, 0, 1500)], JSON_UNESCAPED_UNICODE) : $json;
    }

    /** Depth/length limiter + secret key masking. */
    private function scrub($v, int $depth)
    {
        if ($depth > 3) {
            return '…';
        }
        if (is_array($v)) {
            $out = [];
            $n = 0;
            foreach ($v as $k => $x) {
                if (++$n > 40) {
                    break;
                }
                $key = is_string($k) ? $this->clip($k, 60) : $k;
                $out[$key] = is_string($k) && preg_match('/pass|secret|token|otp|authorization|cookie|jwt/i', $k) ? '…' : $this->scrub($x, $depth + 1);
            }

            return $out;
        }
        if (is_string($v)) {
            return $this->clip($v, 600);
        }
        if (is_scalar($v) || $v === null) {
            return $v;
        }

        return null;
    }

    public static function deviceOf(string $ua): string
    {
        if ($ua === '') {
            return '';
        }
        if (stripos($ua, 'UIPApp') !== false || stripos($ua, 'capacitor') !== false) {
            return 'app';
        }
        if (preg_match('/ipad|tablet|playbook|silk|(android(?!.*mobile))/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/mobi|iphone|ipod|android|blackberry|opera mini|iemobile/i', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }

    public static function browserOf(string $ua): string
    {
        $map = ['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox/' => 'Firefox', 'CriOS' => 'Chrome', 'FxiOS' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'];
        foreach ($map as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                return $name;
            }
        }

        return $ua === '' ? '' : 'Other';
    }

    // ----------------------------------------------------------------- read

    /** @return array<string,mixed> normalised filter set */
    public function filters(array $in): array
    {
        $list = function ($v, array $allowed) {
            $items = is_array($v) ? $v : explode(',', (string) $v);
            $items = array_values(array_filter(array_map('trim', $items), fn ($x) => $x !== '' && in_array($x, $allowed, true)));

            return $items;
        };

        return [
            'q'          => trim((string) ($in['q'] ?? '')),
            'types'      => $list($in['type'] ?? '', self::TYPES),
            'severities' => $list($in['severity'] ?? '', self::SEVERITIES),
            'source'     => in_array($in['source'] ?? '', ['client', 'server'], true) ? $in['source'] : '',
            'session_id' => trim((string) ($in['session_id'] ?? '')),
            'path'       => trim((string) ($in['path'] ?? '')),
            'date_from'  => $this->dateOrNull($in['date_from'] ?? null, false),
            'date_to'    => $this->dateOrNull($in['date_to'] ?? null, true),
            'only_errors' => filter_var($in['only_errors'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function dateOrNull($v, bool $endOfDay): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        try {
            $c = Carbon::parse($v);

            return ($endOfDay && strlen($v) <= 10 ? $c->endOfDay() : $c)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function base(int $userId, array $f, bool $withSeverityType = true)
    {
        $q = DB::table(self::TABLE)->where('user_id', $userId);
        if ($withSeverityType) {
            if ($f['types']) {
                $q->whereIn('type', $f['types']);
            }
            if ($f['severities']) {
                $q->whereIn('severity', $f['severities']);
            }
        }
        if ($f['only_errors']) {
            $q->whereIn('severity', ['warning', 'error']);
        }
        if ($f['source'] !== '') {
            $q->where('source', $f['source']);
        }
        if ($f['session_id'] !== '') {
            $q->where('session_id', $f['session_id']);
        }
        if ($f['path'] !== '') {
            $q->where('path', 'like', '%' . $this->like($f['path']) . '%');
        }
        if ($f['date_from']) {
            $q->where('occurred_at', '>=', $f['date_from']);
        }
        if ($f['date_to']) {
            $q->where('occurred_at', '<=', $f['date_to']);
        }
        if ($f['q'] !== '') {
            $like = '%' . $this->like($f['q']) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('title', 'like', $like)->orWhere('path', 'like', $like)->orWhere('target', 'like', $like)
                    ->orWhere('endpoint', 'like', $like)->orWhere('message', 'like', $like)->orWhere('url', 'like', $like);
            });
        }

        return $q;
    }

    private function like(string $s): string
    {
        return addcslashes($s, '%_\\');
    }

    /** @return array{rows: array<int,array<string,mixed>>, total: int} */
    public function search(int $userId, array $f, int $page, int $perPage): array
    {
        $q = $this->base($userId, $f);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('occurred_at')->orderByDesc('id')->forPage($page, $perPage)->get()->map(fn ($r) => $this->present($r))->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<int,array<string,mixed>> */
    public function searchAll(int $userId, array $f, int $limit = 20000): array
    {
        return $this->base($userId, $f)->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get()->map(fn ($r) => $this->present($r))->all();
    }

    /** @return array<string,mixed> */
    public function present($r): array
    {
        $details = null;
        if (!empty($r->details)) {
            $decoded = is_array($r->details) ? $r->details : json_decode((string) $r->details, true);
            $details = is_array($decoded) ? $decoded : null;
        }

        return [
            'id'          => (int) $r->id,
            'session_id'  => $r->session_id,
            'occurred_at' => $this->iso($r->occurred_at),
            'type'        => $r->type,
            'severity'    => $r->severity,
            'source'      => $r->source,
            'title'       => $r->title,
            'path'        => $r->path,
            'target'      => $r->target,
            'method'      => $r->method,
            'endpoint'    => $r->endpoint,
            'url'         => $r->url,
            'status'      => $r->status !== null ? (int) $r->status : null,
            'duration_ms' => $r->duration_ms !== null ? (int) $r->duration_ms : null,
            'message'     => $r->message,
            'ip'          => $r->ip,
            'device'      => $r->device,
            'browser'     => $r->browser,
            'user_agent'  => $r->user_agent,
            'details'     => $details,
        ];
    }

    private function iso($v): ?string
    {
        if (!$v) {
            return null;
        }
        try {
            return Carbon::parse($v)->format('Y-m-d\TH:i:s.v');
        } catch (\Throwable $e) {
            return (string) $v;
        }
    }

    /** @return array<string,mixed> */
    public function summary(int $userId, array $f): array
    {
        // Counts ignore the type/severity pickers so the chips always show what is available for the rest of the filters.
        $scope = $this->base($userId, $f + [], false);
        $byType = (clone $scope)->select('type', DB::raw('COUNT(*) as c'))->groupBy('type')->pluck('c', 'type')->all();
        $bySeverity = (clone $scope)->select('severity', DB::raw('COUNT(*) as c'))->groupBy('severity')->pluck('c', 'severity')->all();
        $total = array_sum($byType);

        $range = (clone $scope)->selectRaw('MIN(occurred_at) as first_at, MAX(occurred_at) as last_at, COUNT(DISTINCT session_id) as sessions')->first();

        $topPages = (clone $scope)->where('type', 'page_view')->select('path', DB::raw('COUNT(*) as c'))->whereNotNull('path')
            ->groupBy('path')->orderByDesc('c')->limit(8)->get()->map(fn ($r) => ['path' => $r->path, 'count' => (int) $r->c])->all();
        $topClicks = (clone $scope)->whereIn('type', ['click', 'rage_click'])->select('target', DB::raw('COUNT(*) as c'))->whereNotNull('target')
            ->groupBy('target')->orderByDesc('c')->limit(8)->get()->map(fn ($r) => ['target' => $r->target, 'count' => (int) $r->c])->all();
        $topErrors = (clone $scope)->whereIn('severity', ['warning', 'error'])->select('message', DB::raw('COUNT(*) as c'), DB::raw('MAX(occurred_at) as last_at'))
            ->whereNotNull('message')->groupBy('message')->orderByDesc('c')->limit(8)->get()
            ->map(fn ($r) => ['message' => $r->message, 'count' => (int) $r->c, 'last_at' => $this->iso($r->last_at)])->all();
        $devices = (clone $scope)->select('device', 'browser', DB::raw('COUNT(*) as c'), DB::raw('MAX(occurred_at) as last_at'))->whereNotNull('device')
            ->groupBy('device', 'browser')->orderByDesc('c')->limit(6)->get()
            ->map(fn ($r) => ['device' => $r->device, 'browser' => $r->browser, 'count' => (int) $r->c, 'last_at' => $this->iso($r->last_at)])->all();
        $ips = (clone $scope)->select('ip', DB::raw('COUNT(*) as c'), DB::raw('MAX(occurred_at) as last_at'))->whereNotNull('ip')
            ->groupBy('ip')->orderByDesc('last_at')->limit(6)->get()
            ->map(fn ($r) => ['ip' => $r->ip, 'count' => (int) $r->c, 'last_at' => $this->iso($r->last_at)])->all();

        // Events per day, last 14 days (zero-filled) — a driver-neutral PHP bucket instead of DATE() SQL.
        $since = Carbon::now()->subDays(13)->startOfDay();
        $days = [];
        for ($i = 0; $i < 14; $i++) {
            $days[$since->copy()->addDays($i)->format('Y-m-d')] = ['date' => $since->copy()->addDays($i)->format('Y-m-d'), 'events' => 0, 'errors' => 0];
        }
        (clone $scope)->where('occurred_at', '>=', $since->format('Y-m-d H:i:s'))->select('id', 'occurred_at', 'severity')
            ->chunkById(2000, function ($chunk) use (&$days) {
                foreach ($chunk as $r) {
                    $d = substr((string) $r->occurred_at, 0, 10);
                    if (isset($days[$d])) {
                        $days[$d]['events']++;
                        if ($r->severity !== 'info') {
                            $days[$d]['errors']++;
                        }
                    }
                }
            });

        return [
            'total'       => (int) $total,
            'by_type'     => array_map('intval', $byType),
            'by_severity' => array_map('intval', $bySeverity),
            'errors'      => (int) (($bySeverity['error'] ?? 0)),
            'warnings'    => (int) (($bySeverity['warning'] ?? 0)),
            'sessions'    => (int) ($range->sessions ?? 0),
            'first_at'    => $this->iso($range->first_at ?? null),
            'last_at'     => $this->iso($range->last_at ?? null),
            'top_pages'   => $topPages,
            'top_clicks'  => $topClicks,
            'top_errors'  => $topErrors,
            'devices'     => $devices,
            'ips'         => $ips,
            'per_day'     => array_values($days),
        ];
    }

    /** Browser visits (one row per session_id) with their length, event count, error count and entry page. */
    public function sessions(int $userId, array $f, int $limit = 40): array
    {
        $scope = $this->base($userId, $f, false)->whereNotNull('session_id');
        $rows = (clone $scope)->select(
            'session_id',
            DB::raw('MIN(occurred_at) as started_at'),
            DB::raw('MAX(occurred_at) as ended_at'),
            DB::raw('COUNT(*) as events'),
            DB::raw("SUM(CASE WHEN severity = 'error' THEN 1 ELSE 0 END) as errors"),
            DB::raw("SUM(CASE WHEN severity = 'warning' THEN 1 ELSE 0 END) as warnings"),
            DB::raw("SUM(CASE WHEN type = 'page_view' THEN 1 ELSE 0 END) as pages"),
            DB::raw('MAX(ip) as ip'),
            DB::raw('MAX(device) as device'),
            DB::raw('MAX(browser) as browser')
        )->groupBy('session_id')->orderByDesc('ended_at')->limit($limit)->get();

        return $rows->map(function ($r) use ($userId) {
            $first = DB::table(self::TABLE)->where('user_id', $userId)->where('session_id', $r->session_id)->where('type', 'page_view')->orderBy('occurred_at')->value('path');
            $started = Carbon::parse($r->started_at);
            $ended = Carbon::parse($r->ended_at);

            return [
                'session_id' => $r->session_id,
                'started_at' => $this->iso($r->started_at),
                'ended_at'   => $this->iso($r->ended_at),
                'duration_s' => max(0, $ended->diffInSeconds($started)),
                'events'     => (int) $r->events,
                'errors'     => (int) $r->errors,
                'warnings'   => (int) $r->warnings,
                'pages'      => (int) $r->pages,
                'entry_page' => $first,
                'ip'         => $r->ip,
                'device'     => $r->device,
                'browser'    => $r->browser,
            ];
        })->all();
    }

    public function prune(int $days): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $deleted = 0;
        $cut = Carbon::now()->subDays(max(1, $days))->format('Y-m-d H:i:s');
        do {
            $n = DB::table(self::TABLE)->where('occurred_at', '<', $cut)->limit(5000)->delete();
            $deleted += $n;
        } while ($n >= 5000);

        return $deleted;
    }

    /** Admin "clear this user's timeline". */
    public function purgeUser(int $userId): int
    {
        return self::enabled() ? (int) DB::table(self::TABLE)->where('user_id', $userId)->delete() : 0;
    }
}
