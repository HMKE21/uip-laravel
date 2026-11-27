<?php

namespace App\Services;

use App\Services\Tenancy\TenantContext;
use App\Support\PersistentStorage;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * صحة السيرفر (Security Portal — بند 2).
 *
 *  - sample()      : بيقيس CPU / RAM / الديسك / حجم قاعدة البيانات / طابور الـ jobs ويخزّنهم (مرة كل دقيقة من
 *                    `security:health-sample`). القياس للمنصة الأم بس (السيرفر واحد) فبنستخدم دايمًا connection المنصة.
 *  - recordError() : عدّاد أخطاء 5xx لكل (دقيقة، route، status) — بيتنادى من UipHealthTapMiddleware بعد الرد.
 *  - observe()     : بيحسب القيمة اللي القاعدة بتقارنها بالـ threshold (SecurityDetectionService::evaluateHealth بينادي عليه).
 *  - summary()     : بيانات صفحة Server Health.
 *
 * ملاحظات القياس:
 *  - CPU/RAM بيتقروا من cgroup الـ container لو موجود (Railway/Docker)، وإلا من /proc، وإلا من sys_getloadavg (CPU بس).
 *    CPU = متوسط الاستهلاك بين آخر قياسين (يعني آخر ~دقيقة) مش لحظة واحدة، فأول قياس بعد تشغيل السيرفر بيبقى فاضي.
 *  - أي فشل في القياس بيرجّع null للقيمة دي بس ومبيكسرش الباقي.
 */
class SecurityHealthService
{
    public const T_SAMPLES = 'security_health_samples';
    public const T_ERRORS = 'security_health_errors';
    public const RETENTION_DAYS = 14;
    public const ALERT_PREFIX = 'health_';

    private static ?bool $ready = null;

    // ---------------------------------------------------------------- plumbing

    private function conn(): Connection
    {
        return DB::connection(TenantContext::centralConnection());
    }

    public function available(): bool
    {
        if (self::$ready === null) {
            try {
                $schema = Schema::connection(TenantContext::centralConnection());
                self::$ready = $schema->hasTable(self::T_SAMPLES) && $schema->hasTable(self::T_ERRORS);
            } catch (\Throwable) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    // ---------------------------------------------------------------- sampling

    /** @return array<string,mixed>|null الصف اللي اتخزّن */
    public function sample(): ?array
    {
        if (!$this->available()) {
            return null;
        }

        $cpu = $this->cpu();
        $mem = $this->memory();
        $disk = $this->disk();
        $queue = $this->queue();

        $row = [
            'cpu_percent'        => $cpu['percent'],
            'load1'              => $cpu['load1'],
            'load5'              => $cpu['load5'],
            'cores'              => $cpu['cores'],
            'mem_percent'        => $mem['percent'],
            'mem_used_mb'        => $mem['used_mb'],
            'mem_total_mb'       => $mem['total_mb'],
            'disk_percent'       => $disk['percent'],
            'disk_free_gb'       => $disk['free_gb'],
            'disk_total_gb'      => $disk['total_gb'],
            'db_size_mb'         => $this->dbSizeMb(),
            'queue_pending'      => $queue['pending'],
            'queue_failed'       => $queue['failed'],
            'queue_oldest_age_s' => $queue['oldest_age_s'],
            'created_at'         => now(),
        ];
        $this->conn()->table(self::T_SAMPLES)->insert($row);

        return $row;
    }

    /** @return array{percent:?float,load1:?float,load5:?float,cores:?float} */
    private function cpu(): array
    {
        $load = function_exists('sys_getloadavg') ? (@sys_getloadavg() ?: []) : [];
        $out = [
            'percent' => null,
            'load1'   => isset($load[0]) ? round((float) $load[0], 2) : null,
            'load5'   => isset($load[1]) ? round((float) $load[1], 2) : null,
            'cores'   => null,
        ];

        // 1) cgroup v2 (container): usage_usec / (elapsed * cores_allowed).
        $stat = $this->readFile('/sys/fs/cgroup/cpu.stat');
        if ($stat !== null && preg_match('/^usage_usec\s+(\d+)/m', $stat, $m)) {
            $cores = $this->cgroupCores();
            $out['cores'] = round($cores, 2);
            $out['percent'] = $this->cpuDelta('cg', (float) $m[1], $cores * 1_000_000);
            return $out;
        }

        // 2) /proc/stat (بيشوف الجهاز كله): (busy delta / total delta).
        $proc = $this->readFile('/proc/stat');
        if ($proc !== null && preg_match('/^cpu\s+(.+)$/m', $proc, $m)) {
            $f = array_map('floatval', preg_split('/\s+/', trim($m[1])));
            $idle = ($f[3] ?? 0) + ($f[4] ?? 0);
            $total = array_sum(array_slice($f, 0, 8));
            $out['cores'] = (float) $this->coreCount();
            $prev = Cache::get('health:cpu:proc');
            Cache::put('health:cpu:proc', [$total, $idle], 3600);
            if (is_array($prev) && $total > $prev[0]) {
                $out['percent'] = round(max(0, min(100, (1 - (($idle - $prev[1]) / ($total - $prev[0]))) * 100)), 1);
            }
            return $out;
        }

        // 3) آخر حل: load average نسبة لعدد الأنوية (تقريب).
        $cores = (float) $this->coreCount();
        $out['cores'] = $cores;
        if ($out['load1'] !== null && $cores > 0) {
            $out['percent'] = round(min(100, ($out['load1'] / $cores) * 100), 1);
        }
        return $out;
    }

    /** نسبة الاستهلاك بين القياس الحالي والسابق لعدّاد تراكمي (usec). $capacityPerSecond = usec متاحة في الثانية. */
    private function cpuDelta(string $key, float $usage, float $capacityPerSecond): ?float
    {
        $now = microtime(true);
        $prev = Cache::get('health:cpu:' . $key);
        Cache::put('health:cpu:' . $key, [$usage, $now], 3600);
        if (!is_array($prev) || $now - $prev[1] < 5 || $usage < $prev[0] || $capacityPerSecond <= 0) {
            return null;
        }
        $pct = (($usage - $prev[0]) / (($now - $prev[1]) * $capacityPerSecond)) * 100;
        return round(max(0, min(100, $pct)), 1);
    }

    private function cgroupCores(): float
    {
        $max = $this->readFile('/sys/fs/cgroup/cpu.max');
        if ($max !== null && preg_match('/^(\d+)\s+(\d+)/', trim($max), $m) && (int) $m[2] > 0) {
            return max(0.05, (int) $m[1] / (int) $m[2]);
        }
        return (float) $this->coreCount();
    }

    private function coreCount(): int
    {
        $info = $this->readFile('/proc/cpuinfo');
        if ($info !== null && ($n = preg_match_all('/^processor\s*:/m', $info)) > 0) {
            return (int) $n;
        }
        return 1;
    }

    /** @return array{percent:?float,used_mb:?int,total_mb:?int} */
    private function memory(): array
    {
        $none = ['percent' => null, 'used_mb' => null, 'total_mb' => null];

        // cgroup v2 بحد ذاكرة (container): الشغّال فعلًا = current - inactive_file (الكاش مش بيتحسب).
        $limit = $this->readFile('/sys/fs/cgroup/memory.max');
        $cur = $this->readFile('/sys/fs/cgroup/memory.current');
        if ($limit !== null && $cur !== null && ctype_digit(trim($limit)) && (float) trim($limit) > 0) {
            $stat = $this->readFile('/sys/fs/cgroup/memory.stat') ?? '';
            $inactive = preg_match('/^inactive_file\s+(\d+)/m', $stat, $m) ? (float) $m[1] : 0.0;
            $used = max(0.0, (float) trim($cur) - $inactive);
            $total = (float) trim($limit);
            return ['percent' => round(min(100, $used / $total * 100), 1), 'used_mb' => (int) round($used / 1048576), 'total_mb' => (int) round($total / 1048576)];
        }

        $mi = $this->readFile('/proc/meminfo');
        if ($mi !== null && preg_match('/^MemTotal:\s+(\d+)/m', $mi, $t) && preg_match('/^MemAvailable:\s+(\d+)/m', $mi, $a) && (int) $t[1] > 0) {
            $total = (float) $t[1] * 1024;
            $used = max(0.0, $total - (float) $a[1] * 1024);
            return ['percent' => round($used / $total * 100, 1), 'used_mb' => (int) round($used / 1048576), 'total_mb' => (int) round($total / 1048576)];
        }
        return $none;
    }

    /** @return array{percent:?float,free_gb:?float,total_gb:?float} */
    private function disk(): array
    {
        try {
            $path = PersistentStorage::root() ?: base_path();
            if (!is_dir($path)) {
                $path = base_path();
            }
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if (!$total || $free === false) {
                return ['percent' => null, 'free_gb' => null, 'total_gb' => null];
            }
            return [
                'percent'  => round(($total - $free) / $total * 100, 1),
                'free_gb'  => round($free / 1073741824, 2),
                'total_gb' => round($total / 1073741824, 2),
            ];
        } catch (\Throwable) {
            return ['percent' => null, 'free_gb' => null, 'total_gb' => null];
        }
    }

    /** حجم قاعدة بيانات المنصة بالميجا. بيتخزّن في الكاش 5 دقايق (information_schema بطيء على قواعد كبيرة). */
    private function dbSizeMb(): ?float
    {
        try {
            return Cache::remember('health:db-size', 300, function () {
                $conn = $this->conn();
                $driver = $conn->getDriverName();
                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    $v = $conn->selectOne('SELECT ROUND(SUM(data_length + index_length) / 1048576, 1) AS mb FROM information_schema.tables WHERE table_schema = DATABASE()');
                    return $v && $v->mb !== null ? (float) $v->mb : null;
                }
                if ($driver === 'pgsql') {
                    $v = $conn->selectOne('SELECT ROUND(pg_database_size(current_database()) / 1048576.0, 1) AS mb');
                    return $v ? (float) $v->mb : null;
                }
                if ($driver === 'sqlite') {
                    $file = (string) $conn->getDatabaseName();
                    return $file !== '' && $file !== ':memory:' && is_file($file) ? round(filesize($file) / 1048576, 1) : null;
                }
                return null;
            });
        } catch (\Throwable $e) {
            Log::warning('Health: db size failed: ' . $e->getMessage());
            return null;
        }
    }

    /** @return array{pending:?int,failed:?int,oldest_age_s:?int} */
    private function queue(): array
    {
        $out = ['pending' => null, 'failed' => null, 'oldest_age_s' => null];
        $driver = (string) config('queue.default', 'sync');

        try {
            $out['failed'] = (int) $this->conn()->table((string) config('queue.failed.table', 'failed_jobs'))->count();
        } catch (\Throwable) {
        }

        try {
            if ($driver === 'database') {
                $table = (string) config('queue.connections.database.table', 'jobs');
                $q = $this->conn()->table($table)->whereNull('reserved_at')->where('available_at', '<=', time());
                $out['pending'] = (int) (clone $q)->count();
                $oldest = (clone $q)->min('available_at');
                $out['oldest_age_s'] = $oldest ? max(0, time() - (int) $oldest) : 0;
            } elseif ($driver !== 'sync' && $driver !== 'null') {
                $out['pending'] = (int) Queue::size(); // redis / sqs / beanstalkd: العمر مش متاح
            }
        } catch (\Throwable) {
        }
        return $out;
    }

    private function readFile(string $path): ?string
    {
        try {
            if (!@is_readable($path)) {
                return null;
            }
            $v = @file_get_contents($path);
            return $v === false ? null : $v;
        } catch (\Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------- 5xx

    public function recordError(int $status, ?string $route): void
    {
        if ($status < 500 || $status > 599 || !$this->available()) {
            return;
        }
        $route = mb_substr(trim((string) $route) !== '' ? (string) $route : 'unmatched', 0, 120);
        $minute = now()->startOfMinute()->toDateTimeString();
        $where = ['minute_at' => $minute, 'route' => $route, 'status' => $status];

        $t = $this->conn()->table(self::T_ERRORS);
        if (!(clone $t)->where($where)->increment('hits')) {
            $t->insertOrIgnore($where + ['hits' => 0]);
            $this->conn()->table(self::T_ERRORS)->where($where)->increment('hits');
        }
    }

    // ---------------------------------------------------------------- rule observation

    /**
     * القيمة اللي القاعدة بتقارنها بالـ threshold. null = مفيش بيانات كفاية (مفيش قياسات في الـ window مثلًا).
     *
     * @return array{observed:float,extra:array<string,mixed>}|null
     */
    public function observe(object $rule, array $def): ?array
    {
        if (!$this->available() || !isset($def['health'])) {
            return null;
        }
        $h = $def['health'];
        $window = max(1, (int) $rule->window_minutes);
        $now = now();

        if (!empty($def['spike'])) {
            return $this->observeSpike($rule, $h, $window);
        }

        $value = $this->aggregate($h, $now->copy()->subMinutes($window), $now);
        if ($value === null) {
            return null;
        }
        return ['observed' => $value, 'extra' => ['window_minutes' => $window, 'current' => $value, 'metric' => $h['metric']]];
    }

    /**
     * ارتفاع مفاجئ: متوسط/أقصى/مجموع آخر window مقابل الفترة اللي قبلها (baseline_minutes). threshold = نسبة الزيادة %.
     * config.min_value = أرضية مطلقة (قيم صغيرة جدًا مبتعتبرش ارتفاع). لازم يبقى فيه قياسات كفاية في الـ baseline.
     */
    private function observeSpike(object $rule, array $h, int $window): ?array
    {
        $cfg = json_decode((string) $rule->config, true) ?: [];
        $baselineMin = max(10, min(1440, (int) ($cfg['baseline_minutes'] ?? 60)));
        $minValue = max(0.0, (float) ($cfg['min_value'] ?? 0));

        $now = now();
        $split = $now->copy()->subMinutes($window);
        $start = $split->copy()->subMinutes($baselineMin);

        $have = (int) $this->conn()->table(self::T_SAMPLES)->where('created_at', '>=', $start)->where('created_at', '<', $split)->count();
        if ($have < max(5, (int) floor($baselineMin / 3))) {
            return null; // لسه معندناش "طبيعي" نقارن بيه
        }

        $current = $this->aggregate($h, $split, $now);
        $base = $this->aggregate($h, $start, $split);
        if ($current === null || $base === null) {
            return null;
        }
        // المجاميع (5xx) نقارنها كمعدّل في الدقيقة عشان فرق طول الفترتين.
        $perMinute = ($h['agg'] ?? '') === 'sum';
        $cmpCur = $perMinute ? $current / $window : $current;
        $cmpBase = $perMinute ? $base / $baselineMin : $base;

        if ($current < $minValue) {
            return ['observed' => 0.0, 'extra' => []];
        }
        $increase = $cmpBase > 0 ? (($cmpCur - $cmpBase) / $cmpBase) * 100 : ($cmpCur > 0 ? 1000000.0 : 0.0);

        return ['observed' => round(min(1000000, max(0, $increase)), 1), 'extra' => [
            'window_minutes' => $window, 'baseline_minutes' => $baselineMin, 'current' => round($current, 1),
            'baseline' => round($base, 1), 'per_minute' => $perMinute, 'metric' => $h['metric'],
        ]];
    }

    /** @param array{metric:string,agg:string,div?:int|float} $h */
    private function aggregate(array $h, $from, $to): ?float
    {
        $metric = $h['metric'];
        $agg = $h['agg'];
        $div = (float) ($h['div'] ?? 1);

        if ($metric === 'http_5xx') {
            $sum = (int) $this->conn()->table(self::T_ERRORS)->where('minute_at', '>=', $from->copy()->startOfMinute())->where('minute_at', '<', $to)->sum('hits');
            // لو مفيش ولا قياس في الفترة معناها السيرفر مش مراقَب — مش "صفر أخطاء".
            $seen = $this->conn()->table(self::T_SAMPLES)->where('created_at', '>=', $from)->where('created_at', '<', $to)->exists();
            return $seen ? (float) $sum : null;
        }

        if ($agg === 'failed_window') {
            try {
                $t = (string) config('queue.failed.table', 'failed_jobs');
                if (!$this->conn()->getSchemaBuilder()->hasColumn($t, 'failed_at')) {
                    return null;
                }
                return (float) $this->conn()->table($t)->where('failed_at', '>=', $from)->count();
            } catch (\Throwable) {
                return null;
            }
        }

        $q = $this->conn()->table(self::T_SAMPLES)->where('created_at', '>=', $from)->where('created_at', '<', $to)->whereNotNull($metric);
        if ($agg === 'growth') {
            $first = (clone $q)->orderBy('created_at')->value($metric);
            $last = (clone $q)->orderByDesc('created_at')->value($metric);
            return $first === null || $last === null ? null : max(0.0, (float) $last - (float) $first);
        }
        if ($agg === 'last') {
            $v = (clone $q)->orderByDesc('created_at')->value($metric);
        } else {
            $fn = ['avg' => 'avg', 'max' => 'max'][$agg] ?? 'avg';
            $v = $q->{$fn}($metric);
        }
        return $v === null ? null : round((float) $v / $div, 2);
    }

    // ---------------------------------------------------------------- maintenance

    public function prune(?int $days = null): array
    {
        if (!$this->available()) {
            return ['samples' => 0, 'errors' => 0];
        }
        $cut = now()->subDays($days ?: self::RETENTION_DAYS);
        return [
            'samples' => (int) $this->conn()->table(self::T_SAMPLES)->where('created_at', '<', $cut)->delete(),
            'errors'  => (int) $this->conn()->table(self::T_ERRORS)->where('minute_at', '<', $cut)->delete(),
        ];
    }

    // ---------------------------------------------------------------- dashboard

    /** @return array<string,mixed> */
    public function summary(int $windowMinutes): array
    {
        $windowMinutes = max(10, min(10080, $windowMinutes));
        $since = now()->subMinutes($windowMinutes);
        $bucket = max(60, (int) ceil($windowMinutes * 60 / 120)); // حوالي 120 نقطة في الرسم

        $latest = $this->conn()->table(self::T_SAMPLES)->orderByDesc('id')->first();
        $age = $latest ? max(0, time() - (int) strtotime((string) $latest->created_at)) : null;
        $status = !$latest ? 'no_data' : ($age > 180 ? 'stale' : 'ok');

        $series = [];
        $this->conn()->table(self::T_SAMPLES)->where('created_at', '>=', $since)->orderBy('id')
            ->select(['created_at', 'cpu_percent', 'mem_percent', 'disk_percent', 'db_size_mb', 'queue_pending', 'queue_oldest_age_s'])
            ->chunk(2000, function ($rows) use (&$series, $bucket) {
                foreach ($rows as $r) {
                    $t = (int) floor(strtotime((string) $r->created_at) / $bucket) * $bucket;
                    foreach (['cpu_percent', 'mem_percent', 'disk_percent', 'db_size_mb', 'queue_pending', 'queue_oldest_age_s'] as $k) {
                        if ($r->{$k} !== null) {
                            $series[$t][$k][] = (float) $r->{$k};
                        }
                    }
                    $series[$t] ??= [];
                }
            });
        ksort($series);
        $points = [];
        foreach ($series as $t => $cols) {
            $avg = fn (string $k) => isset($cols[$k]) ? round(array_sum($cols[$k]) / count($cols[$k]), 1) : null;
            $max = fn (string $k) => isset($cols[$k]) ? round(max($cols[$k]), 1) : null;
            $points[] = [
                't' => $t, 'cpu' => $avg('cpu_percent'), 'mem' => $avg('mem_percent'), 'disk' => $max('disk_percent'),
                'db_mb' => $max('db_size_mb'), 'queue' => $max('queue_pending'),
                'queue_wait_min' => isset($cols['queue_oldest_age_s']) ? round(max($cols['queue_oldest_age_s']) / 60, 1) : null,
            ];
        }

        $errBuckets = [];
        $routes = [];
        $total = 0;
        $this->conn()->table(self::T_ERRORS)->where('minute_at', '>=', $since)->orderBy('id')
            ->select(['minute_at', 'route', 'status', 'hits'])
            ->chunk(2000, function ($rows) use (&$errBuckets, &$routes, &$total, $bucket) {
                foreach ($rows as $r) {
                    $t = (int) floor(strtotime((string) $r->minute_at) / $bucket) * $bucket;
                    $errBuckets[$t] = ($errBuckets[$t] ?? 0) + (int) $r->hits;
                    $k = $r->status . ' ' . $r->route;
                    $routes[$k] = ['route' => $r->route, 'status' => (int) $r->status, 'hits' => ($routes[$k]['hits'] ?? 0) + (int) $r->hits];
                    $total += (int) $r->hits;
                }
            });
        $errSeries = [];
        for ($t = (int) floor($since->timestamp / $bucket) * $bucket; $t <= time(); $t += $bucket) {
            $errSeries[] = ['t' => $t, 'errors' => $errBuckets[$t] ?? 0];
        }
        usort($routes, fn ($a, $b) => $b['hits'] <=> $a['hits']);

        return [
            'status'  => $status,
            'sampler' => ['last_sample_at' => $latest->created_at ?? null, 'age_seconds' => $age],
            'now'     => $latest ? [
                'cpu_percent' => $this->f($latest->cpu_percent), 'load1' => $this->f($latest->load1), 'load5' => $this->f($latest->load5),
                'cores' => $this->f($latest->cores), 'mem_percent' => $this->f($latest->mem_percent),
                'mem_used_mb' => $this->i($latest->mem_used_mb), 'mem_total_mb' => $this->i($latest->mem_total_mb),
                'disk_percent' => $this->f($latest->disk_percent), 'disk_free_gb' => $this->f($latest->disk_free_gb), 'disk_total_gb' => $this->f($latest->disk_total_gb),
                'db_size_mb' => $this->f($latest->db_size_mb), 'queue_pending' => $this->i($latest->queue_pending), 'queue_failed' => $this->i($latest->queue_failed),
                'queue_oldest_age_s' => $this->i($latest->queue_oldest_age_s),
            ] : null,
            'series'  => $points,
            'errors'  => ['total' => $total, 'series' => $errSeries, 'top_routes' => array_slice(array_values($routes), 0, 8), 'bucket_seconds' => $bucket],
            'window_minutes' => $windowMinutes,
        ];
    }

    private function f($v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    private function i($v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
