<?php

namespace App\Services;

use App\Repositories\BlockedIpRepository;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security Portal — بند 9: لوحة التهديدات الحية (Live Threat Monitor).
 *
 * مفيش جدول جديد ولا كتابة هنا — الخدمة دي بتقرا بس من المصادر الحقيقية الموجودة وتوحّدها في تدفّق واحد:
 *   - security_detection_events : failed_login / login_success / file_download / role_granted / attack_probe / path_probe (بند 10)
 *   - security_detection_hits   : كل مرة قاعدة كشف اتفعّلت
 *   - security_alerts           : التنبيهات
 *   - blocked_ips               : عمليات الحظر الجديدة (يدوي/تلقائي)
 *   - security_logs             : أحداث الأمان الحرجة (حظر دخول، malware، قفل حساب...)
 *
 * الجغرافيا: country من جدول ip_geo_cache (اللي GeoIpService بيملاه) — القراءة هنا cache-only عشان الـ polling
 * ما يستناش مزوّد خارجي. resolvePending() هي الوحيدة اللي بتنادي GeoIpService، بميزانية صغيرة وبـ cooldown لو المزوّد واقع.
 */
class SecurityThreatMonitorService
{
    /** window => [دقائق، حجم الـ bucket بالثواني] */
    public const WINDOWS = [
        '1h'  => ['minutes' => 60,    'bucket' => 300],
        '6h'  => ['minutes' => 360,   'bucket' => 1800],
        '24h' => ['minutes' => 1440,  'bucket' => 3600],
        '7d'  => ['minutes' => 10080, 'bucket' => 21600],
    ];

    /** الأنواع اللي بتتعدّ "تهديد" في الأرقام والخريطة والاتجاهات (login_success/file_download/role_granted سياق بس). */
    public const HOSTILE = ['failed_login', 'detection_hit', 'alert', 'ip_blocked', 'log_threat', 'attack_probe', 'path_probe'];

    private const DETECTION_EVENT_TYPES = ['failed_login', 'login_success', 'file_download', 'role_granted', 'attack_probe', 'path_probe', 'data_export', 'data_delete', 'data_bulk_update', 'config_change'];
    private const SEV_RANK = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
    private const ROW_CAP = 15000;

    /** بادئات رسائل security_logs اللي تهم المراقبة (Failed login مستبعدة عمدًا: موجودة بالفعل في detection_events). */
    private const LOG_THREATS = [
        'Blocked login'                              => 'critical',
        'Malware detected in uploaded file'          => 'critical',
        'Account locked after failed login attempts' => 'critical',
        'Login blocked - account locked'             => 'medium',
        'Trusted device validator mismatch'          => 'medium',
    ];

    private bool $truncated = false;

    public function __construct(private BlockedIpRepository $blocked, private GeoIpService $geo)
    {
    }

    public function available(): bool
    {
        return $this->has('security_detection_events') || $this->has('security_alerts') || $this->has('security_logs');
    }

    // ------------------------------------------------------------------ feed

    /**
     * التدفّق المباشر. since = epoch seconds (آخر حدث الكلاينت شافه) — بنرجّع أي حدث ts >= since والكلاينت بيشيل التكرار بالـ uid.
     * من غير since: آخر $minutes دقيقة.
     *
     * @param array{kinds?:array<int,string>,min_severity?:string,ip?:string} $filters
     * @return array{events:array<int,array<string,mixed>>,server_time:int,latest_ts:int}
     */
    public function feed(?int $since, int $limit, array $filters = [], int $minutes = 60): array
    {
        $limit = max(1, min(200, $limit));
        $from = $since !== null && $since > 0
            ? now()->setTimestamp($since)
            : now()->subMinutes(max(1, min(1440, $minutes)));

        $rows = $this->fetch($from, 300, true, array_values(array_filter((array) ($filters['kinds'] ?? []))) ?: null);

        $kinds = array_values(array_filter((array) ($filters['kinds'] ?? [])));
        $min = self::SEV_RANK[$filters['min_severity'] ?? ''] ?? null;
        $ipNeedle = trim((string) ($filters['ip'] ?? ''));
        $rows = array_values(array_filter($rows, function (array $r) use ($kinds, $min, $ipNeedle) {
            if ($kinds && !in_array($r['kind'], $kinds, true)) {
                return false;
            }
            if ($min !== null && (self::SEV_RANK[$r['severity']] ?? 0) < $min) {
                return false;
            }
            if ($ipNeedle !== '' && stripos((string) $r['ip'], $ipNeedle) === false) {
                return false;
            }
            return true;
        }));

        usort($rows, fn ($a, $b) => $b['ts'] <=> $a['ts'] ?: strcmp($b['uid'], $a['uid']));
        $rows = array_slice($rows, 0, $limit);

        $geo = $this->geoMap(array_column($rows, 'ip'));
        $blocks = $this->activeBlocks();
        $ua = $this->deviceMap(array_column($rows, 'ip'));
        foreach ($rows as &$r) {
            $r = $this->decorate($r, $geo, $blocks, $ua);
        }
        unset($r);

        return [
            'events'      => $rows,
            'server_time' => time(),
            'latest_ts'   => $rows ? (int) $rows[0]['ts'] : 0,
        ];
    }

    // ------------------------------------------------------------------ summary

    /**
     * كاش 8 ثواني لكل (tenant + window): أكتر من موظف أمن فاتح الشاشة مايضربوش الـ DB بنفس التجميع كل مرة.
     *
     * @return array<string,mixed>
     */
    public function summary(string $window): array
    {
        $key = isset(self::WINDOWS[$window]) ? $window : '1h';

        return Cache::remember('thrmon:sum:' . TenantContext::ns() . $key, 8, fn () => $this->buildSummary($key));
    }

    /** @return array<string,mixed> */
    private function buildSummary(string $key): array
    {
        $w = self::WINDOWS[$key];
        $nowTs = time();
        $fromTs = $nowTs - $w['minutes'] * 60;

        $this->truncated = false;
        // التجميع بيتم على الأنواع العدائية بس (login_success بيبقى آلاف — بنعدّه بـ COUNT مش بنسحبه صفوف).
        $rows = $this->fetch(now()->setTimestamp($fromTs), self::ROW_CAP, false, self::HOSTILE);
        $hostile = $rows;

        // ---- trend (buckets مظبوطة على فرق التوقيت المحلي)
        $b = $w['bucket'];
        $off = (int) now()->utcOffset() * 60;
        $startB = intdiv($fromTs + $off, $b) * $b - $off;
        $endB = intdiv($nowTs + $off, $b) * $b - $off;
        $trend = [];
        for ($t = $startB; $t <= $endB; $t += $b) {
            $trend[$t] = ['t' => $t, 'failed_login' => 0, 'detection_hit' => 0, 'alert' => 0, 'ip_blocked' => 0, 'log_threat' => 0, 'attack_probe' => 0, 'path_probe' => 0];
        }
        foreach ($rows as $r) {
            $slot = intdiv($r['ts'] + $off, $b) * $b - $off;
            if (isset($trend[$slot]) && array_key_exists($r['kind'], $trend[$slot])) {
                $trend[$slot][$r['kind']]++;
            }
        }

        // ---- counters
        $kinds = array_fill_keys(['failed_login', 'login_success', 'file_download', 'role_granted', 'detection_hit', 'alert', 'ip_blocked', 'log_threat', 'attack_probe', 'path_probe', 'data_export', 'data_delete', 'data_bulk_update', 'config_change'], 0);
        $sev = ['info' => 0, 'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
        foreach ($rows as $r) {
            $kinds[$r['kind']] = ($kinds[$r['kind']] ?? 0) + 1;
        }
        foreach ($hostile as $r) {
            $sev[$r['severity']] = ($sev[$r['severity']] ?? 0) + 1;
        }
        // أرقام دقيقة حتى لو الصفوف اتقصّت بالـ cap.
        foreach ($this->detectionCounts($fromTs) as $type => $n) {
            $kinds[$type] = $n;
        }

        $last5 = 0;
        $prev5 = 0;
        foreach ($hostile as $r) {
            if ($r['ts'] >= $nowTs - 300) {
                $last5++;
            } elseif ($r['ts'] >= $nowTs - 600) {
                $prev5++;
            }
        }

        // ---- IPs / targets
        $ips = [];
        $targets = [];
        foreach ($hostile as $r) {
            if ($r['ip']) {
                $ips[$r['ip']] ??= ['ip' => $r['ip'], 'events' => 0, 'failed_login' => 0, 'detection_hit' => 0, 'last_ts' => 0, 'sev' => 0];
                $ips[$r['ip']]['events']++;
                if ($r['kind'] === 'failed_login') {
                    $ips[$r['ip']]['failed_login']++;
                }
                if ($r['kind'] === 'detection_hit') {
                    $ips[$r['ip']]['detection_hit']++;
                }
                $ips[$r['ip']]['last_ts'] = max($ips[$r['ip']]['last_ts'], $r['ts']);
                $ips[$r['ip']]['sev'] = max($ips[$r['ip']]['sev'], self::SEV_RANK[$r['severity']] ?? 0);
            }
            if ($r['kind'] === 'failed_login' && $r['who']) {
                $targets[$r['who']] ??= ['who' => $r['who'], 'attempts' => 0, 'ips' => []];
                $targets[$r['who']]['attempts']++;
                if ($r['ip']) {
                    $targets[$r['who']]['ips'][$r['ip']] = true;
                }
            }
        }

        $geo = $this->geoMap(array_keys($ips));
        $blocks = $this->activeBlocks();
        $rankName = array_flip(self::SEV_RANK);

        // ---- countries + city-level locations
        $ua = $this->deviceMap(array_slice(array_keys($ips), 0, 250));
        $countries = [];
        $locations = [];
        $devices = ['mobile' => 0, 'tablet' => 0, 'desktop' => 0, 'bot' => 0, 'tool' => 0, 'unknown' => 0];
        $connections = ['datacenter' => 0, 'proxy' => 0, 'mobile' => 0, 'broadband' => 0, 'unknown' => 0];
        $internal = 0;
        $pending = [];
        $refine = 0;
        foreach ($ips as $ip => $i) {
            $g = $geo[$ip] ?? null;
            if ($g && $g['status'] === 'local') {
                $internal += $i['events'];
                continue;
            }
            $dev = $ua[$ip]['type'] ?? 'unknown';
            $devices[$dev] = ($devices[$dev] ?? 0) + $i['events'];
            $conn = $g['connection'] ?? null;
            $connections[$conn && isset($connections[$conn]) ? $conn : 'unknown'] += $i['events'];

            if ($g && $g['code']) {
                $countries[$g['code']] ??= ['code' => $g['code'], 'events' => 0, 'ips' => 0, 'failed_login' => 0];
                $countries[$g['code']]['events'] += $i['events'];
                $countries[$g['code']]['ips']++;
                $countries[$g['code']]['failed_login'] += $i['failed_login'];
                if ($g['lat'] !== null && $g['lng'] !== null) {
                    $lk = $g['code'] . ':' . round($g['lat'], 2) . ':' . round($g['lng'], 2);
                    $locations[$lk] ??= [
                        'key' => $lk, 'lat' => $g['lat'], 'lng' => $g['lng'], 'country' => $g['code'], 'city' => $g['city'], 'region' => $g['region'],
                        'events' => 0, 'ips' => 0, 'failed_login' => 0, 'accuracy_km' => $g['accuracy_km'], 'confidence' => 0, 'list' => [],
                    ];
                    $L = &$locations[$lk];
                    $L['events'] += $i['events'];
                    $L['ips']++;
                    $L['failed_login'] += $i['failed_login'];
                    $L['accuracy_km'] = max((int) $L['accuracy_km'], (int) ($g['accuracy_km'] ?? 0)) ?: null;
                    $L['confidence'] += (int) ($g['confidence'] ?? 0);
                    $L['list'][] = [
                        'ip' => $ip, 'events' => $i['events'], 'connection' => $conn, 'isp' => $g['isp'], 'asn' => $g['asn'],
                        'proxy' => $g['proxy'], 'hosting' => $g['hosting'], 'mobile' => $g['mobile'], 'confidence' => $g['confidence'],
                        'device' => $ua[$ip] ?? null, 'blocked' => false,
                    ];
                    unset($L);
                }
                if (!$g['detail'] && in_array($g['status'], ['ok', 'unknown'], true)) {
                    $refine++;
                }
            } elseif (!$g) {
                $pending[$ip] = $i['events'];
            }
        }
        usort($countries, fn ($a, $b2) => $b2['events'] <=> $a['events']);

        $blocksNow = $this->activeBlocks();
        $locations = array_values($locations);
        usort($locations, fn ($a, $b2) => $b2['events'] <=> $a['events']);
        $locations = array_map(function ($L) use ($blocksNow) {
            $L['confidence'] = $L['ips'] ? (int) round($L['confidence'] / $L['ips']) : null;
            usort($L['list'], fn ($a, $b2) => $b2['events'] <=> $a['events']);
            $L['list'] = array_map(function ($x) use ($blocksNow) {
                $x['blocked'] = $this->isBlocked($x['ip'], $blocksNow);
                return $x;
            }, array_slice($L['list'], 0, 6));
            return $L;
        }, array_slice($locations, 0, 150));

        $topIps = array_values($ips);
        usort($topIps, fn ($a, $b2) => $b2['events'] <=> $a['events'] ?: $b2['last_ts'] <=> $a['last_ts']);
        $topIps = array_map(function ($i) use ($geo, $blocks, $rankName, $ua) {
            $g = $geo[$i['ip']] ?? null;
            return [
                'ip'            => $i['ip'],
                'events'        => $i['events'],
                'failed_login'  => $i['failed_login'],
                'detection_hit' => $i['detection_hit'],
                'last_ts'       => $i['last_ts'],
                'severity'      => $rankName[$i['sev']] ?? 'low',
                'country'       => $g['code'] ?? null,
                'city'          => $g['city'] ?? null,
                'connection'    => $g['connection'] ?? null,
                'isp'           => $g['isp'] ?? null,
                'device'        => $ua[$i['ip']] ?? null,
                'geo'           => $g ? $g['status'] : 'pending',
                'blocked'       => $this->isBlocked($i['ip'], $blocks),
            ];
        }, array_slice($topIps, 0, 8));

        $topTargets = array_values($targets);
        usort($topTargets, fn ($a, $b2) => $b2['attempts'] <=> $a['attempts']);
        $topTargets = array_map(fn ($t) => ['who' => $t['who'], 'attempts' => $t['attempts'], 'ips' => count($t['ips'])], array_slice($topTargets, 0, 6));

        return [
            'window'         => $key,
            'bucket_seconds' => $b,
            'from_ts'        => $fromTs,
            'to_ts'          => $nowTs,
            'truncated'      => $this->truncated,
            'kpis'           => [
                'threats'        => $kinds['failed_login'] + $kinds['detection_hit'] + $kinds['alert'] + $kinds['ip_blocked'] + $kinds['log_threat'] + $kinds['attack_probe'] + $kinds['path_probe'],
                'attack_probes'  => $kinds['attack_probe'],
                'path_probes'    => $kinds['path_probe'],
                'failed_logins'  => $kinds['failed_login'],
                'detection_hits' => $kinds['detection_hit'],
                'alerts'         => $kinds['alert'],
                'new_blocks'     => $kinds['ip_blocked'],
                'critical'       => ($sev['critical'] ?? 0) + ($sev['high'] ?? 0),
                'unique_ips'     => count($ips),
                'active_blocks'  => count($blocks),
                'success_logins' => $kinds['login_success'],
                'last_5m'        => $last5,
                'prev_5m'        => $prev5,
            ],
            'kinds'          => $kinds,
            'severity'       => $sev,
            'trend'          => array_values($trend),
            'top_ips'        => $topIps,
            'top_targets'    => $topTargets,
            'countries'      => array_slice($countries, 0, 40),
            'locations'      => $locations,
            'devices'        => $devices,
            'connections'    => $connections,
            'geo'            => [
                'internal_events' => $internal,
                'pending_ips'     => count($pending) + $refine,
                'pending_events'  => array_sum($pending),
                'resolved_events' => array_sum(array_column($countries, 'events')),
            ],
        ];
    }

    // ------------------------------------------------------------------ geo resolution

    /**
     * بتحلّ عدد صغير من IPs العامة اللي لسه مالهاش دولة (الأكتر نشاطًا الأول). لو المزوّد فشل بنوقف المحاولات 2 دقيقة.
     *
     * @return array{resolved:int,pending:int,cooldown:bool}
     */
    public function resolvePending(int $budget = 3): array
    {
        $cdKey = 'thrmon:geo-cd:' . TenantContext::ns();
        if (Cache::get($cdKey)) {
            return ['resolved' => 0, 'pending' => $this->countPending(), 'cooldown' => true];
        }

        $missing = $this->missingIps();
        $resolved = 0;
        foreach (array_slice($missing, 0, max(1, min(5, $budget))) as $ip) {
            try {
                $res = $this->geo->resolve($ip, true);
            } catch (\Throwable) {
                $res = ['status' => 'error'];
            }
            if (($res['status'] ?? 'error') === 'error') {
                Cache::put($cdKey, 1, 120);
                break;
            }
            $resolved++;
        }

        return ['resolved' => $resolved, 'pending' => max(0, count($missing) - $resolved), 'cooldown' => (bool) Cache::get($cdKey)];
    }

    private function countPending(): int
    {
        return count($this->missingIps());
    }

    /** @return array<int,string> IPs عامة (آخر 24 ساعة) من غير صف في ip_geo_cache، الأكتر نشاطًا الأول. */
    private function missingIps(): array
    {
        $rows = array_filter($this->fetch(now()->subDay(), self::ROW_CAP, false, self::HOSTILE), fn ($r) => (bool) $r['ip']);
        $count = [];
        foreach ($rows as $r) {
            $count[$r['ip']] = ($count[$r['ip']] ?? 0) + 1;
        }
        arsort($count);
        $geo = $this->geoMap(array_keys($count));
        $out = [];
        foreach (array_keys($count) as $ip) {
            $g = $geo[$ip] ?? null;
            $needs = !$g || (!$g['detail'] && in_array($g['status'], ['ok', 'unknown'], true));
            if ($needs && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $out[] = (string) $ip;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ sources

    /**
     * كل المصادر في شكل موحّد. $full=false => من غير نصوص/تفاصيل (للتجميع بس).
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetch(\DateTimeInterface $from, int $cap, bool $full, ?array $kinds = null): array
    {
        // $kinds = null => كل الأنواع؛ غير كده بنتخطّى المصادر اللي مالهاش نوع مطلوب (وبنفلتر detection_events في الـ SQL).
        $want = fn (array $k) => $kinds === null || array_intersect($k, $kinds) !== [];

        return array_merge(
            $want(self::DETECTION_EVENT_TYPES) ? $this->detectionEvents($from, $cap, $full, $kinds) : [],
            $want(['detection_hit']) ? $this->detectionHits($from, $cap, $full) : [],
            $want(['alert']) ? $this->alerts($from, $cap, $full) : [],
            $want(['ip_blocked']) ? $this->blockEvents($from, $cap, $full) : [],
            $want(['log_threat']) ? $this->logThreats($from, $cap, $full) : []
        );
    }

    /** @return array<string,int> عدّ دقيق لكل نوع من أحداث detection_events في الفترة. */
    private function detectionCounts(int $fromTs): array
    {
        if (!$this->has('security_detection_events')) {
            return [];
        }
        try {
            $out = [];
            $rows = DB::table('security_detection_events')
                ->where('created_at', '>=', date('Y-m-d H:i:s', $fromTs))
                ->whereIn('event_type', self::DETECTION_EVENT_TYPES)
                ->select('event_type', DB::raw('COUNT(*) as c'))->groupBy('event_type')->get();
            foreach ($rows as $r) {
                $out[(string) $r->event_type] = (int) $r->c;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function detectionEvents(\DateTimeInterface $from, int $cap, bool $full, ?array $kinds = null): array
    {
        if (!$this->has('security_detection_events')) {
            return [];
        }
        $sev = ['failed_login' => 'low', 'login_success' => 'info', 'file_download' => 'info', 'role_granted' => 'medium', 'attack_probe' => 'high', 'path_probe' => 'low', 'data_export' => 'info', 'data_delete' => 'info', 'data_bulk_update' => 'info', 'config_change' => 'medium'];
        try {
            $q = DB::table('security_detection_events')->where('created_at', '>=', $from);
            if ($kinds !== null) {
                $q->whereIn('event_type', array_values(array_intersect($kinds, self::DETECTION_EVENT_TYPES)));
            }
            $rows = $q->orderByDesc('id')->limit($cap)
                ->get(['id', 'event_type', 'user_id', 'ip_address', 'identifier', 'bytes', 'meta', 'created_at']);
            $this->truncated = $this->truncated || $rows->count() >= $cap;
            return $rows->map(function ($r) use ($sev, $full) {
                $meta = $full && $r->meta ? (json_decode((string) $r->meta, true) ?: []) : [];
                return $this->row('de:' . $r->id, (string) $r->event_type, $sev[$r->event_type] ?? 'info', $r->ip_address, $r->identifier, $r->created_at, $full ? [
                    'meta' => match ($r->event_type) {
                        'file_download' => ['bytes' => $r->bytes !== null ? (int) $r->bytes : null],
                        'role_granted'  => ['role' => $meta['role'] ?? null],
                        'attack_probe'  => [
                            'category' => $meta['category'] ?? null, 'where' => $meta['where'] ?? null, 'field' => $meta['field'] ?? null,
                            'method' => $meta['method'] ?? null, 'path' => $meta['path'] ?? null, 'status' => $meta['status'] ?? null,
                            'sample' => isset($meta['sample']) ? mb_substr((string) $meta['sample'], 0, 120) : null,
                        ],
                        'path_probe'    => ['method' => $meta['method'] ?? null, 'path' => $meta['path'] ?? null, 'status' => $meta['status'] ?? null],
                        'data_export', 'data_delete', 'data_bulk_update', 'config_change'
                                        => ['method' => $meta['method'] ?? null, 'route' => $meta['route'] ?? null, 'target' => $meta['target'] ?? null, 'user_id' => $r->user_id],
                        default         => null,
                    },
                ] : []);
            })->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function detectionHits(\DateTimeInterface $from, int $cap, bool $full): array
    {
        if (!$this->has('security_detection_hits')) {
            return [];
        }
        try {
            $q = DB::table('security_detection_hits as h')->where('h.created_at', '>=', $from)->orderByDesc('h.id')->limit($cap);
            $cols = ['h.id', 'h.rule_key', 'h.subject_type', 'h.subject_value', 'h.ip_address', 'h.observed', 'h.threshold', 'h.severity', 'h.actions_taken', 'h.created_at'];
            if ($this->has('security_detection_rules')) {
                $q->leftJoin('security_detection_rules as r', 'r.id', '=', 'h.rule_id');
                $cols = array_merge($cols, ['r.name_en', 'r.name_ar']);
            }
            $rows = $q->get($cols);
            $this->truncated = $this->truncated || $rows->count() >= $cap;
            return $rows->map(function ($r) use ($full) {
                $extra = [];
                if ($full) {
                    $extra = [
                        'title'    => $r->name_en ?? $r->rule_key,
                        'title_ar' => $r->name_ar ?? null,
                        'meta'     => [
                            'rule_key' => $r->rule_key,
                            'subject'  => $r->subject_value,
                            'observed' => (int) $r->observed,
                            'threshold' => (int) $r->threshold,
                            'actions'  => $r->actions_taken ? (json_decode((string) $r->actions_taken, true) ?: []) : [],
                        ],
                    ];
                }
                return $this->row('dh:' . $r->id, 'detection_hit', $this->normSeverity((string) $r->severity), $r->ip_address, in_array($r->subject_type, ['identifier', 'account'], true) ? $r->subject_value : null, $r->created_at, $extra);
            })->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function alerts(\DateTimeInterface $from, int $cap, bool $full): array
    {
        if (!$this->has('security_alerts')) {
            return [];
        }
        try {
            $rows = DB::table('security_alerts')->where('created_at', '>=', $from)->orderByDesc('id')->limit($cap)
                ->get(['id', 'type', 'severity', 'title', 'message', 'source_ip', 'status', 'created_at']);
            $this->truncated = $this->truncated || $rows->count() >= $cap;
            return $rows->map(fn ($r) => $this->row('al:' . $r->id, 'alert', $this->normSeverity((string) $r->severity), $r->source_ip, null, $r->created_at, $full ? [
                'title'  => $r->title,
                'detail' => $r->message ? mb_substr((string) $r->message, 0, 240) : null,
                'meta'   => ['type' => $r->type, 'status' => $r->status],
                'ref'    => ['type' => 'alert', 'id' => (int) $r->id],
            ] : []))->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function blockEvents(\DateTimeInterface $from, int $cap, bool $full): array
    {
        if (!$this->has('blocked_ips')) {
            return [];
        }
        try {
            $hasSource = $this->hasCol('blocked_ips', 'source');
            $rows = DB::table('blocked_ips')->where('created_at', '>=', $from)->orderByDesc('id')->limit($cap)
                ->get(array_merge(['id', 'ip_address', 'reason', 'created_at'], $hasSource ? ['source'] : []));
            $this->truncated = $this->truncated || $rows->count() >= $cap;
            return $rows->map(function ($r) use ($hasSource, $full) {
                $auto = $hasSource && ($r->source ?? 'manual') === 'auto';
                $ip = str_contains((string) $r->ip_address, '/') ? null : $r->ip_address; // CIDR مالوش دولة
                return $this->row('bl:' . $r->id, 'ip_blocked', $auto ? 'high' : 'medium', $ip, null, $r->created_at, $full ? [
                    'detail' => $r->reason ? mb_substr((string) $r->reason, 0, 240) : null,
                    'meta'   => ['entry' => $r->ip_address, 'source' => $hasSource ? ($r->source ?? 'manual') : 'manual'],
                ] : []);
            })->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function logThreats(\DateTimeInterface $from, int $cap, bool $full): array
    {
        if (!$this->has('security_logs')) {
            return [];
        }
        try {
            $q = DB::table('security_logs')->where('created_at', '>=', $from)->where(function ($w) {
                foreach (array_keys(self::LOG_THREATS) as $p) {
                    $w->orWhere('event_type', 'like', $p . '%');
                }
            })->orderByDesc('id')->limit($cap);
            $rows = $q->get(['id', 'event_type', 'ip_address', 'meta', 'created_at']);
            $this->truncated = $this->truncated || $rows->count() >= $cap;
            return $rows->map(function ($r) use ($full) {
                $level = 'medium';
                foreach (self::LOG_THREATS as $p => $lv) {
                    if (str_starts_with((string) $r->event_type, $p)) {
                        $level = $lv;
                        break;
                    }
                }
                $meta = $r->meta ? (json_decode((string) $r->meta, true) ?: []) : [];
                return $this->row('sl:' . $r->id, 'log_threat', $level, $r->ip_address, $meta['email'] ?? null, $r->created_at, $full ? [
                    'title' => (string) $r->event_type,
                ] : []);
            })->all();
        } catch (\Throwable) {
            return [];
        }
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function row(string $uid, string $kind, string $severity, $ip, $who, $createdAt, array $extra = []): array
    {
        $ts = strtotime((string) $createdAt) ?: time();
        return array_merge([
            'uid'      => $uid,
            'kind'     => $kind,
            'severity' => $severity,
            'title'    => null,
            'title_ar' => null,
            'detail'   => null,
            'meta'     => null,
            'ref'      => null,
            'ip'       => $ip ? (string) $ip : null,
            'who'      => $who ? mb_substr((string) $who, 0, 190) : null,
            'ts'       => $ts,
            'time'     => date('Y-m-d H:i:s', $ts),
        ], $extra);
    }

    private function normSeverity(string $s): string
    {
        return match (strtolower($s)) {
            'critical' => 'critical',
            'high'     => 'high',
            'warning', 'medium' => 'medium',
            'low'      => 'low',
            default    => 'info',
        };
    }

    /**
     * @param array<string,mixed> $r
     * @param array<string,array{status:string,code:?string}> $geo
     * @param array<int,string> $blocks
     * @return array<string,mixed>
     */
    private function decorate(array $r, array $geo, array $blocks, array $ua = []): array
    {
        $g = $r['ip'] ? ($geo[$r['ip']] ?? null) : null;
        $r['country'] = $g['code'] ?? null;
        $r['city'] = $g['city'] ?? null;
        $r['lat'] = $g['lat'] ?? null;
        $r['lng'] = $g['lng'] ?? null;
        $r['connection'] = $g['connection'] ?? null;
        $r['isp'] = $g['isp'] ?? null;
        $r['device'] = $r['ip'] ? ($ua[$r['ip']] ?? null) : null;
        $r['geo'] = !$r['ip'] ? 'none' : ($g ? $g['status'] : 'pending');
        $r['blocked'] = $r['ip'] ? $this->isBlocked($r['ip'], $blocks) : false;
        return $r;
    }

    /** @return array<int,string> */
    private function activeBlocks(): array
    {
        try {
            return array_values(array_filter(array_map(fn ($b) => (string) ($b['ip_address'] ?? ''), $this->blocked->all())));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<int,string> $blocks */
    private function isBlocked(string $ip, array $blocks): bool
    {
        foreach ($blocks as $entry) {
            if ($entry === $ip || (str_contains($entry, '/') && NetworkControlService::matches($ip, $entry))) {
                return true;
            }
        }
        return false;
    }

    /**
     * ip => ['status' => ok|local|unknown|error, 'code' => ?ISO2]. IP عام مالوش صف = مش في الماب (pending).
     *
     * @param array<int,mixed> $ips
     * @return array<string,array{status:string,code:?string}>
     */
    private function geoMap(array $ips): array
    {
        $out = [];
        $public = [];
        foreach (array_unique(array_filter($ips)) as $ip) {
            $ip = (string) $ip;
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $out[$ip] = ['status' => 'local', 'code' => null];
                continue;
            }
            $public[] = $ip;
        }
        if ($public && $this->has('ip_geo_cache')) {
            $detailCol = $this->hasCol('ip_geo_cache', 'detail_at');
            try {
                foreach (array_chunk($public, 500) as $chunk) {
                    foreach (DB::table('ip_geo_cache')->whereIn('ip_address', $chunk)->get() as $g) {
                        $out[$g->ip_address] = [
                            'status'      => (string) $g->lookup_status,
                            'code'        => $g->country_code ? strtoupper((string) $g->country_code) : null,
                            'city'        => $g->city ?? null,
                            'region'      => $g->region ?? null,
                            'lat'         => isset($g->latitude) ? (float) $g->latitude : null,
                            'lng'         => isset($g->longitude) ? (float) $g->longitude : null,
                            'accuracy_km' => isset($g->accuracy_km) ? (int) $g->accuracy_km : null,
                            'isp'         => $g->isp ?? null,
                            'asn'         => $g->asn ?? null,
                            'connection'  => $g->connection_type ?? null,
                            'proxy'       => (bool) ($g->is_proxy ?? false),
                            'hosting'     => (bool) ($g->is_hosting ?? false),
                            'mobile'      => (bool) ($g->is_mobile ?? false),
                            'confidence'  => isset($g->confidence) ? (int) $g->confidence : null,
                            // من غير العمود (الـ migration لسه) نعتبرها مفصّلة عشان مندخلش في حلقة "ترقية" ما بتخلصش.
                            'detail'      => $detailCol ? ($g->detail_at ?? null) !== null : true,
                        ];
                    }
                }
            } catch (\Throwable) {
                // الجدول مش جاهز => كله pending
            }
        }
        return $out;
    }

    /**
     * ip => ['type','os','browser','label'] من آخر User-Agent اتسجّل للعنوان في security_logs (لو العمود موجود).
     * مؤشر مش دليل: الـ User-Agent بيتزوّر، وأدوات الفحص غالبًا بتسيبه فاضي أو بتحط اسمها.
     *
     * @param array<int,mixed> $ips
     * @return array<string,array{type:string,os:?string,browser:?string,label:?string}>
     */
    private function deviceMap(array $ips): array
    {
        $ips = array_values(array_unique(array_filter(array_map('strval', $ips))));
        if (!$ips || !$this->has('security_logs') || !$this->hasCol('security_logs', 'user_agent')) {
            return [];
        }
        $out = [];
        try {
            foreach (array_chunk($ips, 200) as $chunk) {
                $rows = DB::table('security_logs')->whereIn('ip_address', $chunk)->whereNotNull('user_agent')
                    ->orderByDesc('id')->limit(1500)->get(['ip_address', 'user_agent']);
                foreach ($rows as $r) {
                    if (!isset($out[$r->ip_address])) {
                        $out[$r->ip_address] = \App\Support\UserAgentInfo::parse((string) $r->user_agent);
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    /**
     * تفاصيل عنوان واحد (للـ drill-down): موقع مدمج من كل المزوّدين + الجهاز + نشاطه آخر 24 ساعة. بيعمل lookup حقيقي لو مفيش تفاصيل.
     *
     * @return array<string,mixed>
     */
    public function ipDetail(string $ip): array
    {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Enter a valid IP address.');
        }
        try {
            $geo = $this->geo->lookup($ip);
        } catch (\Throwable) {
            $geo = ['status' => 'error'];
        }
        $events = 0;
        $lastTs = null;
        $kinds = [];
        foreach ($this->fetch(now()->subDay(), self::ROW_CAP, false) as $r) {
            if (($r['ip'] ?? null) === $ip) {
                $events++;
                $kinds[$r['kind']] = ($kinds[$r['kind']] ?? 0) + 1;
                $lastTs = max((int) $lastTs, (int) $r['ts']);
            }
        }
        $ua = $this->deviceMap([$ip]);

        return [
            'ip'      => $ip,
            'geo'     => $geo,
            'device'  => $ua[$ip] ?? null,
            'blocked' => $this->isBlocked($ip, $this->activeBlocks()),
            'last_24h' => ['events' => $events, 'last_ts' => $lastTs, 'kinds' => $kinds],
        ] + $this->accountsForIp($ip);
    }

    /** مدة اعتبار الجلسة "متصل الآن" (آخر نشاط). */
    private const ONLINE_MINUTES = 15;
    /** نافذة البحث عن حسابات دخلت من العنوان ده. */
    private const ACCOUNT_DAYS = 30;

    /**
     * هل العنوان ده تابع لمستخدم مسجّل في المنصة؟ بنربطه بالحسابات اللي *دخلت فعلًا* منه (جلسات user_sessions، دخول ناجح في
     * login_events / security_detection_events، آخر IP دخول في users) — مش أي حدث على العنوان (محاولات الدخول الفاشلة مش بتتحسب
     * هنا، لأن المهاجم ممكن يجرّب إيميل شخص حقيقي). محاولات الفشل بترجع منفصلة في `targeted` عشان المحقق يشوف مين اتستهدف.
     *
     * @return array{accounts:array<int,array<string,mixed>>,accounts_total:int,is_member:bool,targeted:array<int,array<string,mixed>>}
     */
    private function accountsForIp(string $ip): array
    {
        $out = ['accounts' => [], 'accounts_total' => 0, 'is_member' => false, 'targeted' => []];
        if (!$this->has('users')) {
            return $out;
        }
        try {
            $since = now()->subDays(self::ACCOUNT_DAYS);
            $onlineCut = now()->subMinutes(self::ONLINE_MINUTES);
            /** @var array<int,array{sources:array<int,string>,last_ts:int,logins:int,active_here:bool,online_here:bool}> $rel */
            $rel = [];
            $touch = function (int $uid, string $src, $when = null, int $n = 0) use (&$rel) {
                if ($uid <= 0) {
                    return;
                }
                $rel[$uid] ??= ['sources' => [], 'last_ts' => 0, 'logins' => 0, 'active_here' => false, 'online_here' => false];
                if (!in_array($src, $rel[$uid]['sources'], true)) {
                    $rel[$uid]['sources'][] = $src;
                }
                $ts = $when ? (int) strtotime((string) $when) : 0;
                $rel[$uid]['last_ts'] = max($rel[$uid]['last_ts'], $ts);
                $rel[$uid]['logins'] = max($rel[$uid]['logins'], $n);
            };

            // 1) جلسات على العنوان (نشطة أو حديثة)
            if ($this->has('user_sessions')) {
                $rows = DB::table('user_sessions')->where('ip_address', $ip)
                    ->where(fn ($w) => $w->where('is_active', 1)->orWhere('created_at', '>=', $since))
                    ->orderByDesc('id')->limit(300)->get(['user_id', 'is_active', 'expires_at', 'last_activity_at', 'created_at']);
                foreach ($rows as $r) {
                    $uid = (int) $r->user_id;
                    $touch($uid, 'session', $r->last_activity_at ?: $r->created_at);
                    $live = (int) $r->is_active === 1 && (!$r->expires_at || strtotime((string) $r->expires_at) > time());
                    if ($live) {
                        $rel[$uid]['active_here'] = true;
                        if ($r->last_activity_at && strtotime((string) $r->last_activity_at) >= $onlineCut->getTimestamp()) {
                            $rel[$uid]['online_here'] = true;
                        }
                    }
                }
            }

            // 2) دخول ناجح مسجّل
            if ($this->has('login_events')) {
                $rows = DB::table('login_events')->where('ip_address', $ip)->where('success', 1)->whereNotNull('user_id')
                    ->where('created_at', '>=', $since)->groupBy('user_id')
                    ->selectRaw('user_id, COUNT(*) AS n, MAX(created_at) AS last_at')->limit(200)->get();
                foreach ($rows as $r) {
                    $touch((int) $r->user_id, 'login', $r->last_at, (int) $r->n);
                }
            }
            if ($this->has('security_detection_events')) {
                $rows = DB::table('security_detection_events')->where('ip_address', $ip)->where('event_type', 'login_success')
                    ->whereNotNull('user_id')->where('created_at', '>=', $since)->groupBy('user_id')
                    ->selectRaw('user_id, COUNT(*) AS n, MAX(created_at) AS last_at')->limit(200)->get();
                foreach ($rows as $r) {
                    $touch((int) $r->user_id, 'login', $r->last_at, (int) $r->n);
                }
            }

            // 3) آخر IP دخول مخزّن على المستخدم نفسه
            foreach (DB::table('users')->where('last_login_ip', $ip)->whereNull('deleted_at')->limit(100)->get(['id', 'last_login_at']) as $r) {
                $touch((int) $r->id, 'last_login', $r->last_login_at);
            }

            // 4) محاولات دخول فاشلة من العنوان — الحسابات المستهدفة (مش حسابات تابعة للعنوان)
            if ($this->has('security_detection_events')) {
                $rows = DB::table('security_detection_events')->where('ip_address', $ip)->where('event_type', 'failed_login')
                    ->where('created_at', '>=', now()->subDays(7))->groupBy('identifier', 'user_id')
                    ->selectRaw('identifier, user_id, COUNT(*) AS n, MAX(created_at) AS last_at')
                    ->orderByDesc('n')->limit(8)->get();
                $emails = array_values(array_filter(array_map(fn ($r) => $r->user_id ? null : $r->identifier, $rows->all())));
                $byEmail = $emails ? DB::table('users')->whereIn('email', $emails)->whereNull('deleted_at')->pluck('id', 'email')->all() : [];
                $targetIds = array_values(array_filter(array_map(fn ($r) => (int) ($r->user_id ?: ($byEmail[$r->identifier] ?? 0)), $rows->all())));
                $names = $targetIds ? DB::table('users')->whereIn('id', $targetIds)->pluck('full_name', 'id')->all() : [];
                foreach ($rows as $r) {
                    $uid = (int) ($r->user_id ?: ($byEmail[$r->identifier] ?? 0));
                    $out['targeted'][] = [
                        'identifier' => $r->identifier,
                        'user_id'    => $uid ?: null,
                        'full_name'  => $uid ? ($names[$uid] ?? null) : null,
                        'attempts'   => (int) $r->n,
                        'last_ts'    => $r->last_at ? (int) strtotime((string) $r->last_at) : null,
                    ];
                }
            }

            if (!$rel) {
                return $out;
            }

            // الترتيب: متصل الآن من العنوان > جلسة نشطة > الأحدث
            uasort($rel, fn ($a, $b) => [$b['online_here'], $b['active_here'], $b['last_ts']] <=> [$a['online_here'], $a['active_here'], $a['last_ts']]);
            $out['accounts_total'] = count($rel);
            $ids = array_slice(array_keys($rel), 0, 10);

            $cols = ['id', 'uuid', 'full_name', 'email', 'status', 'avatar_path', 'last_login_at', 'last_login_ip', 'lock_permanent', 'locked_until', 'two_factor_enabled'];
            if ($this->hasCol('users', 'username')) {
                $cols[] = 'username';
            }
            $users = DB::table('users')->whereIn('id', $ids)->whereNull('deleted_at')->get($cols)->keyBy('id');

            $roleRows = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->whereIn('ur.user_id', $ids)
                ->orderBy('ur.assigned_at')->get(['ur.user_id', 'r.slug', 'r.name_en', 'r.name_ar']);
            $roles = [];
            foreach ($roleRows as $r) {
                $roles[(int) $r->user_id][] = ['slug' => $r->slug, 'name_en' => $r->name_en, 'name_ar' => $r->name_ar];
            }

            // متصل الآن (من أي عنوان) + عدد الجلسات النشطة
            $live = [];
            if ($this->has('user_sessions')) {
                $rows = DB::table('user_sessions')->whereIn('user_id', $ids)->where('is_active', 1)
                    ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->groupBy('user_id')->selectRaw('user_id, COUNT(*) AS n, MAX(last_activity_at) AS last_at')->get();
                foreach ($rows as $r) {
                    $live[(int) $r->user_id] = ['sessions' => (int) $r->n, 'last_at' => $r->last_at ? (int) strtotime((string) $r->last_at) : 0];
                }
            }

            foreach ($ids as $id) {
                $u = $users->get($id);
                if (!$u) {
                    continue;
                }
                $myRoles = $roles[$id] ?? [];
                $lv = $live[$id] ?? null;
                $out['accounts'][] = [
                    'id'            => (int) $u->id,
                    'uuid'          => $u->uuid,
                    'full_name'     => $u->full_name,
                    'username'      => $u->username ?? null,
                    'email'         => $u->email,
                    'avatar_path'   => $u->avatar_path,
                    'status'        => $u->status,
                    'locked'        => (bool) $u->lock_permanent || ($u->locked_until && strtotime((string) $u->locked_until) > time()),
                    'mfa'           => (bool) $u->two_factor_enabled,
                    'role_slug'     => $myRoles[0]['slug'] ?? null,
                    'roles'         => $myRoles,
                    'sources'       => $rel[$id]['sources'],
                    'logins'        => $rel[$id]['logins'],
                    'seen_ts'       => $rel[$id]['last_ts'] ?: null,
                    'active_here'   => $rel[$id]['active_here'],
                    'online_here'   => $rel[$id]['online_here'],
                    'online_now'    => $lv && $lv['last_at'] >= $onlineCut->getTimestamp(),
                    'sessions'      => $lv['sessions'] ?? 0,
                    'last_login_ts' => $u->last_login_at ? (int) strtotime((string) $u->last_login_at) : null,
                    'last_login_ip' => $u->last_login_ip,
                ];
            }
            $out['is_member'] = $out['accounts'] !== [];
        } catch (\Throwable) {
            // الربط بالحسابات إضافة — أي مشكلة فيه مبتكسرش تفاصيل العنوان.
        }

        return $out;
    }

    private function has(string $table): bool
    {
        static $memo = [];
        if (!array_key_exists($table, $memo)) {
            try {
                $memo[$table] = Schema::hasTable($table);
            } catch (\Throwable) {
                $memo[$table] = false;
            }
        }
        return $memo[$table];
    }

    private function hasCol(string $table, string $col): bool
    {
        static $memo = [];
        $k = $table . '.' . $col;
        if (!array_key_exists($k, $memo)) {
            try {
                $memo[$k] = Schema::hasColumn($table, $col);
            } catch (\Throwable) {
                $memo[$k] = false;
            }
        }
        return $memo[$k];
    }
}
