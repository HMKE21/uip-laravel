<?php

namespace App\Services;

use App\Repositories\BlockedIpRepository;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * صفحة Intrusion Defense (Security Portal — بند 3): نظرة واحدة على التهديدات (بند 1) + صحة السيرفر (بند 2)
 * + الاستجابة التلقائية (قواعد الكشف وإجراءاتها: حظر IP / قفل حساب / إنهاء جلسات / فتح حادث / تنبيه المسؤولين).
 * قراءة بس — التعديل بيتم من Detection Rules / Network Control.
 *
 * كل قسم بيتجمّع لوحده داخل try/catch: لو جدول ناقص أو قسم فشل، الباقي بيظهر والقسم ده بيرجّع null.
 */
class SecurityIntrusionDefenseService
{
    public const WINDOWS = ['1h' => 60, '6h' => 360, '24h' => 1440, '7d' => 10080];

    /** نص الإجراء (actions_taken) → نوع. أي نص تاني (skipped / failed) مبيتعدّش. */
    private const ACTION_PATTERNS = [
        'ip_blocked'       => '/^blocked /',
        'account_locked'   => '/^locked account/',
        'sessions_ended'   => '/^ended /',
        'incident_opened'  => '/^opened incident /',
        'incident_updated' => '/^added to open incident /',
        'admins_notified'  => '/^notified /',
    ];

    public function __construct(
        private SecurityDetectionService $detection,
        private SecurityThreatMonitorService $threats,
        private SecurityHealthService $health,
        private BlockedIpRepository $blocked
    ) {
    }

    /** @return array<string,mixed> */
    public function summary(string $window): array
    {
        $key = isset(self::WINDOWS[$window]) ? $window : '1h';
        $since = now()->subMinutes(self::WINDOWS[$key]);

        $threats = $this->safe(fn () => $this->threatSection($key));
        $health = $this->safe(fn () => $this->healthSection());
        $response = $this->safe(fn () => $this->responseSection($since));
        $open = $this->safe(fn () => $this->openCounts());

        return [
            'window'   => $key,
            'posture'  => $this->posture($threats, $health, $response, $open),
            'threats'  => $threats,
            'health'   => $health,
            'response' => $response,
            'open'     => $open,
        ];
    }

    private function safe(callable $fn): ?array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    // ---------------------------------------------------------------- sections

    private function threatSection(string $key): ?array
    {
        if (!$this->threats->available()) {
            return null;
        }
        $s = $this->threats->summary($key);
        return [
            'kpis'      => $s['kpis'] ?? [],
            'severity'  => $s['severity'] ?? [],
            'trend'     => $s['trend'] ?? [],
            'top_ips'   => array_slice($s['top_ips'] ?? [], 0, 5),
            'countries' => array_slice($s['countries'] ?? [], 0, 5),
            'bucket_seconds' => $s['bucket_seconds'] ?? null,
        ];
    }

    private function healthSection(): ?array
    {
        // القياس للسيرفر كله فمتاح على المنصة الأم بس (زي صفحة Server Health).
        if (TenantContext::active() || !$this->health->available()) {
            return null;
        }
        $h = $this->health->summary(60);
        $alerts = (int) DB::table('security_alerts')->where('type', 'like', SecurityHealthService::ALERT_PREFIX . '%')->where('status', 'open')->count();
        return ['status' => $h['status'], 'now' => $h['now'], 'errors_total' => $h['errors']['total'] ?? 0, 'open_alerts' => $alerts];
    }

    private function responseSection($since): array
    {
        $engine = $this->detection->settings();

        // القواعد: مفعّلة كام وبتعمل إيه.
        $rules = DB::table('security_detection_rules')->get(['id', 'rule_type', 'enabled', 'actions']);
        $byAction = array_fill_keys(SecurityDetectionService::ACTIONS, 0);
        $enabled = 0;
        foreach ($rules as $r) {
            if (!$r->enabled) {
                continue;
            }
            $enabled++;
            foreach ((array) (json_decode((string) $r->actions, true) ?: []) as $a) {
                if (isset($byAction[$a])) {
                    $byAction[$a]++;
                }
            }
        }

        // نشاط الاستجابة داخل الفترة.
        $counts = array_fill_keys(array_keys(self::ACTION_PATTERNS), 0);
        $hitsTotal = 0;
        DB::table('security_detection_hits')->where('created_at', '>=', $since)->select(['id', 'actions_taken'])
            ->chunkById(1000, function ($rows) use (&$counts, &$hitsTotal) {
                foreach ($rows as $r) {
                    $hitsTotal++;
                    foreach ((array) (json_decode((string) $r->actions_taken, true) ?: []) as $note) {
                        foreach (self::ACTION_PATTERNS as $k => $re) {
                            if (preg_match($re, (string) $note)) {
                                $counts[$k]++;
                                break;
                            }
                        }
                    }
                }
            });

        $hasInc = Schema::hasColumn('security_detection_hits', 'incident_id');
        $recent = DB::table('security_detection_hits as h')
            ->leftJoin('security_detection_rules as r', 'r.id', '=', 'h.rule_id')
            ->when($hasInc, fn ($q) => $q->leftJoin('security_incidents as i', 'i.id', '=', 'h.incident_id'))
            ->select(array_merge(
                ['h.id', 'h.rule_key', 'h.subject_type', 'h.subject_value', 'h.severity', 'h.actions_taken', 'h.alert_id', 'h.created_at', 'r.name_en as rule_name', 'r.name_ar as rule_name_ar'],
                $hasInc ? ['h.incident_id', 'i.reference_code as incident_ref'] : []
            ))
            ->orderByDesc('h.id')->limit(12)->get()
            ->map(function ($r) {
                $a = (array) $r;
                $a['actions_taken'] = json_decode((string) $r->actions_taken, true) ?: [];
                return $a;
            })->all();

        $autoBlocks = $this->blocked->activeAuto();
        $lockedAccounts = DB::table('users')->where('lock_reason', 'like', 'Detection rule:%')
            ->where(fn ($q) => $q->where('lock_permanent', 1)->orWhere('locked_until', '>', now()))
            ->orderByDesc('locked_at')->limit(8)->get(['id', 'full_name', 'email', 'locked_at', 'locked_until', 'lock_permanent', 'lock_reason'])
            ->map(fn ($u) => (array) $u)->all();

        $autoIncidents = [];
        if ($hasInc) {
            $autoIncidents = DB::table('security_incidents as i')
                ->whereIn('i.id', DB::table('security_detection_hits')->whereNotNull('incident_id')->select('incident_id'))
                ->whereIn('i.status', ['open', 'investigating', 'contained'])
                ->orderByDesc('i.id')->limit(6)->get(['i.id', 'i.reference_code', 'i.title', 'i.severity', 'i.status', 'i.created_at'])
                ->map(fn ($x) => (array) $x)->all();
        }

        return [
            'engine_enabled'  => (bool) $engine['enabled'],
            'rules'           => ['total' => count($rules), 'enabled' => $enabled, 'by_action' => $byAction],
            'activity'        => $counts + ['hits' => $hitsTotal],
            'recent'          => $recent,
            'active_blocks'   => ['count' => count($autoBlocks), 'items' => array_slice($autoBlocks, 0, 8)],
            'locked_accounts' => ['count' => count($lockedAccounts), 'items' => $lockedAccounts],
            'auto_incidents'  => $autoIncidents,
        ];
    }

    private function openCounts(): array
    {
        return [
            'alerts_open'     => (int) DB::table('security_alerts')->where('status', 'open')->count(),
            'alerts_critical' => (int) DB::table('security_alerts')->where('status', 'open')->whereIn('severity', ['critical', 'high'])->count(),
            'incidents_open'  => (int) DB::table('security_incidents')->whereIn('status', ['open', 'investigating', 'contained'])->count(),
        ];
    }

    /**
     * مستوى الحالة: normal / elevated / under_attack + أسباب. بسيط وقابل للتفسير (مش درجة سحرية):
     *  - under_attack: تنبيه حرج/عالي مفتوح + نشاط عدائي مرتفع في آخر 5 دقايق، أو قفزة حادة.
     *  - elevated   : أي تنبيه حرج/عالي مفتوح، أو نشاط عدائي، أو السيرفر مش صحي، أو محرك الكشف واقف.
     *
     * @return array{level:string,reasons:string[]}
     */
    private function posture(?array $threats, ?array $health, ?array $response, ?array $open): array
    {
        $reasons = [];
        $level = 'normal';
        $bump = function (string $to) use (&$level) {
            $rank = ['normal' => 0, 'elevated' => 1, 'under_attack' => 2];
            if ($rank[$to] > $rank[$level]) {
                $level = $to;
            }
        };

        $critOpen = (int) ($open['alerts_critical'] ?? 0);
        $last5 = (int) ($threats['kpis']['last_5m'] ?? 0);
        $prev5 = (int) ($threats['kpis']['prev_5m'] ?? 0);

        if ($response && !$response['engine_enabled']) {
            $bump('elevated');
            $reasons[] = 'engine_disabled';
        }
        if ($critOpen > 0) {
            $bump('elevated');
            $reasons[] = 'open_critical_alerts';
        }
        if ($last5 >= 20) {
            $bump('elevated');
            $reasons[] = 'hostile_activity';
            if ($critOpen > 0 || ($prev5 > 0 && $last5 >= $prev5 * 3)) {
                $bump('under_attack');
                $reasons[] = 'activity_surge';
            }
        }
        if ($health && in_array($health['status'] ?? '', ['stale'], true)) {
            $bump('elevated');
            $reasons[] = 'health_stale';
        }
        if ($health && (int) ($health['open_alerts'] ?? 0) > 0) {
            $bump('elevated');
            $reasons[] = 'health_alerts';
        }

        return ['level' => $level, 'reasons' => array_values(array_unique($reasons))];
    }
}
