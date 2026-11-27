<?php

namespace App\Services;

use App\Repositories\BlockedIpRepository;
use App\Repositories\SecurityPolicyRepository;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * محرك كشف التهديدات (Security Portal — بند 1).
 *
 * قبل كده التنبيهات كانت ثوابت مكتوبة في SecurityAlertService (BRUTE_FORCE_THRESHOLD = 10 ...) وبتتولّد "إشعار" بس.
 * دلوقتي كل قاعدة ليها threshold + window + severity + إجراءات (alert / block_ip / lock_account / revoke_sessions)
 * والأدمن يظبطها من الشاشة. نقاط الدخول (login فاشل/ناجح، تحميل ملف، منح دور) بتنادي record() وهو اللي بيقرر.
 *
 * المبدأ: أي فشل هنا بيتسجّل بس ومبيكسرش الطلب الأصلي (login مثلًا). والعدّ من جدول security_detection_events
 * (مش من الكاش) فيشتغل صح لو السيرفر أكتر من instance.
 */
class SecurityDetectionService
{
    public const SEVERITIES = ['info', 'warning', 'high', 'critical'];
    public const ACTIONS = ['alert', 'block_ip', 'lock_account', 'revoke_sessions', 'open_incident', 'notify_admins'];

    /** رتبة الخطورة → خطورة الحادث (الحوادث: low/medium/high/critical). */
    private const INCIDENT_SEVERITY = ['info' => 'low', 'warning' => 'medium', 'high' => 'high', 'critical' => 'critical'];
    /** نوع القاعدة → تصنيف الحادث. أي نوع مش هنا = other. */
    private const INCIDENT_CATEGORY = [
        'failed_login_ip' => 'brute_force', 'password_spray' => 'brute_force', 'failed_login_user' => 'brute_force', 'credential_stuffing' => 'brute_force',
        'attack_probe_ip' => 'unauthorized_access', 'path_scan' => 'unauthorized_access', 'new_country' => 'unauthorized_access',
        'new_device' => 'unauthorized_access', 'privilege_escalation' => 'unauthorized_access', 'sensitive_change' => 'unauthorized_access',
        'mass_export' => 'data_leak', 'mass_download' => 'data_leak', 'mass_delete' => 'policy_violation', 'mass_modify' => 'policy_violation',
    ];

    /**
     * كتالوج أنواع القواعد. الفرونت بيرسم الفورم منه (الوحدة، الإجراءات المسموحة، هل فيه window).
     *  event    : نوع الحدث اللي بيغذّي القاعدة
     *  subject  : بنعدّ على إيه (ip | identifier | user)
     *  distinct : عدّ قيم مختلفة بدل الأحداث
     *  baseline : قاعدة "جديد" (country | device) مش بتعتمد على threshold/window
     */
    public const TYPES = [
        'failed_login_ip' => [
            'event' => 'failed_login', 'subject' => 'ip', 'alert_type' => 'brute_force',
            'unit' => 'failed attempts', 'windowed' => true, 'actions' => ['alert', 'block_ip', 'open_incident', 'notify_admins'],
        ],
        'password_spray' => [
            'event' => 'failed_login', 'subject' => 'ip', 'distinct' => 'identifier', 'alert_type' => 'password_spray',
            'unit' => 'different accounts', 'windowed' => true, 'actions' => ['alert', 'block_ip', 'open_incident', 'notify_admins'],
        ],
        'failed_login_user' => [
            'event' => 'failed_login', 'subject' => 'identifier', 'alert_type' => 'account_under_attack',
            'unit' => 'failed attempts', 'windowed' => true, 'actions' => ['alert', 'lock_account', 'open_incident', 'notify_admins'],
        ],
        'mass_download' => [
            'event' => 'file_download', 'subject' => 'user', 'alert_type' => 'mass_download',
            'unit' => 'downloads', 'windowed' => true, 'actions' => ['alert', 'revoke_sessions', 'lock_account', 'open_incident', 'notify_admins'],
        ],
        'new_country' => [
            'event' => 'login_success', 'baseline' => 'country', 'alert_type' => 'suspicious_login_location',
            'unit' => '', 'windowed' => false, 'actions' => ['alert', 'revoke_sessions', 'open_incident', 'notify_admins'],
        ],
        'new_device' => [
            'event' => 'login_success', 'baseline' => 'device', 'alert_type' => 'new_device_login',
            'unit' => '', 'windowed' => false, 'actions' => ['alert', 'revoke_sessions', 'open_incident', 'notify_admins'],
        ],
        'privilege_escalation' => [
            'event' => 'role_granted', 'alert_type' => 'privilege_escalation',
            'unit' => '', 'windowed' => false, 'actions' => ['alert', 'open_incident', 'notify_admins'],
        ],
        // بند 10 — كشف هجمات الـ API (UipAttackProbeMiddleware بيغذّي الأحداث دي)
        'attack_probe_ip' => [
            'event' => 'attack_probe', 'subject' => 'ip', 'alert_type' => 'injection_attempt',
            'unit' => 'attack attempts', 'windowed' => true, 'actions' => ['alert', 'block_ip', 'open_incident', 'notify_admins'],
        ],
        'path_scan' => [
            'event' => 'path_probe', 'subject' => 'ip', 'alert_type' => 'path_scanning',
            'unit' => 'probing requests', 'windowed' => true, 'actions' => ['alert', 'block_ip', 'open_incident', 'notify_admins'],
        ],
        // بند 1 (مراقبة الداتا) — UipDataGuardMiddleware بيغذّي الأحداث دي (config/data_guard.php)
        'mass_export' => [
            'event' => 'data_export', 'subject' => 'user', 'alert_type' => 'mass_export',
            'unit' => 'exports', 'windowed' => true, 'actions' => ['alert', 'revoke_sessions', 'lock_account', 'open_incident', 'notify_admins'],
        ],
        'mass_delete' => [
            'event' => 'data_delete', 'subject' => 'user', 'sum' => 'amount', 'alert_type' => 'mass_delete',
            'unit' => 'deleted items', 'windowed' => true, 'actions' => ['alert', 'revoke_sessions', 'lock_account', 'open_incident', 'notify_admins'],
        ],
        'mass_modify' => [
            'event' => 'data_bulk_update', 'subject' => 'user', 'sum' => 'amount', 'alert_type' => 'mass_modify',
            'unit' => 'modified items', 'windowed' => true, 'actions' => ['alert', 'revoke_sessions', 'lock_account', 'open_incident', 'notify_admins'],
        ],
        'sensitive_change' => [
            'event' => 'config_change', 'subject' => 'user', 'alert_type' => 'sensitive_change',
            'unit' => 'sensitive changes', 'windowed' => true, 'actions' => ['alert', 'revoke_sessions', 'open_incident', 'notify_admins'],
        ],
        // بند 2 — صحة السيرفر. SecurityHealthService بيقيس كل دقيقة (security:health-sample) و evaluateHealth() بيقارن بالـ threshold.
        //  threshold = القيمة، window_minutes = المدة اللي بنقيس عليها. الإجراء الوحيد: تنبيه (مفيش حظر/قفل لمشكلة في السيرفر).
        //  health.agg: avg | max | last | growth (آخر - أول قيمة في الـ window) | sum | failed_window (failed_jobs جديدة).
        //  spike: threshold = نسبة الزيادة % عن الطبيعي (config.baseline_minutes) و config.min_value = أرضية مطلقة.
        'server_cpu' => [
            'event' => 'health_sample', 'alert_type' => 'health_cpu', 'unit' => '% CPU (average)', 'windowed' => true, 'actions' => ['alert', 'notify_admins'],
            'health' => ['metric' => 'cpu_percent', 'agg' => 'avg'],
        ],
        'server_ram' => [
            'event' => 'health_sample', 'alert_type' => 'health_ram', 'unit' => '% RAM (average)', 'windowed' => true, 'actions' => ['alert', 'notify_admins'],
            'health' => ['metric' => 'mem_percent', 'agg' => 'avg'],
        ],
        'server_disk' => [
            'event' => 'health_sample', 'alert_type' => 'health_disk', 'unit' => '% disk used', 'windowed' => true, 'actions' => ['alert', 'notify_admins'],
            'health' => ['metric' => 'disk_percent', 'agg' => 'max'],
        ],
        'db_size' => [
            'event' => 'health_sample', 'alert_type' => 'health_db_size', 'unit' => 'MB database size', 'windowed' => true, 'actions' => ['alert', 'notify_admins'],
            'health' => ['metric' => 'db_size_mb', 'agg' => 'last'],
        ],
        'db_growth' => [
            'event' => 'health_sample', 'alert_type' => 'health_db_growth', 'unit' => 'MB growth in the window', 'windowed' => true, 'actions' => ['alert', 'notify_admins'],
            'health' => ['metric' => 'db_size_mb', 'agg' => 'growth'],
        ],
        'queue_backlog' => [
            'event' => 'health_sample', 'alert_type' => 'health_queue_backlog', 'unit' => 'jobs waiting', 'windowed' => true, 'actions' => ['alert', 'notify_admins'],
            'health' => ['metric' => 'queue_pending', 'agg' => 'max'],
        ],
        'queue_stalled' => [
            'event' => 'health_sample', 'alert_type' => 'health_queue_stalled', 'unit' => 'minutes the oldest job has waited', 'windowed' => true, 'actions' => ['alert', 'notify_admins'],
            'health' => ['metric' => 'queue_oldest_age_s', 'agg' => 'max', 'div' => 60],
        ],
        'queue_failures' => [
            'event' => 'health_sample', 'alert_type' => 'health_queue_failures', 'unit' => 'failed jobs', 'windowed' => true, 'actions' => ['alert', 'notify_admins'],
            'health' => ['metric' => 'queue_failed', 'agg' => 'failed_window'],
        ],
        'http_5xx' => [
            'event' => 'health_sample', 'alert_type' => 'health_5xx', 'unit' => 'server errors (5xx)', 'windowed' => true, 'actions' => ['alert', 'notify_admins'],
            'health' => ['metric' => 'http_5xx', 'agg' => 'sum'],
        ],
        'cpu_spike' => [
            'event' => 'health_sample', 'alert_type' => 'health_cpu_spike', 'unit' => '% above normal CPU', 'windowed' => true, 'actions' => ['alert', 'notify_admins'],
            'health' => ['metric' => 'cpu_percent', 'agg' => 'avg'], 'spike' => true,
        ],
        'ram_spike' => [
            'event' => 'health_sample', 'alert_type' => 'health_ram_spike', 'unit' => '% above normal RAM', 'windowed' => true, 'actions' => ['alert', 'notify_admins'],
            'health' => ['metric' => 'mem_percent', 'agg' => 'avg'], 'spike' => true,
        ],
        'http_5xx_spike' => [
            'event' => 'health_sample', 'alert_type' => 'health_5xx_spike', 'unit' => '% above normal error rate', 'windowed' => true, 'actions' => ['alert', 'notify_admins'],
            'health' => ['metric' => 'http_5xx', 'agg' => 'sum'], 'spike' => true,
        ],
        'queue_spike' => [
            'event' => 'health_sample', 'alert_type' => 'health_queue_spike', 'unit' => '% above normal queue size', 'windowed' => true, 'actions' => ['alert', 'notify_admins'],
            'health' => ['metric' => 'queue_pending', 'agg' => 'max'], 'spike' => true,
        ],
        // Credential stuffing: فشل دخول كتير من عناوين وحسابات كتير (موزّع) — مش مرتبط بـ IP أو حساب واحد.
        'credential_stuffing' => [
            'event' => 'failed_login', 'subject' => 'global', 'alert_type' => 'credential_stuffing',
            'unit' => 'failed attempts', 'windowed' => true, 'actions' => ['alert', 'open_incident', 'notify_admins'],
        ],
    ];

    private const SETTINGS_KEY = 'detection.settings';
    private const SETTINGS_DEFAULTS = [
        'enabled'             => true,
        'never_block_private' => true,
        'exempt_ips'          => [],
        'retention_days'      => 14,
    ];

    private static ?bool $ready = null;
    /** @var array<string,array<int,object>> */
    private static array $rulesMemo = [];

    public function __construct(
        private SecurityPolicyRepository $policies,
        private BlockedIpRepository $blocked
    ) {
    }

    // ---------------------------------------------------------------- settings

    /** الجداول موجودة؟ (الكود ممكن يتنشر قبل الـ migration — الـ login لازم يفضل شغال). */
    public function available(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable('security_detection_rules') && Schema::hasTable('security_detection_events');
            } catch (\Throwable) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** هل فيه قاعدة مفعّلة من النوع ده؟ (بتستخدمها SecurityAlertService عشان تتجنب التنبيه المزدوج مع المنطق القديم). */
    public function handles(string $ruleType): bool
    {
        if (!$this->available()) {
            return false;
        }
        try {
            return $this->settings()['enabled'] && $this->enabledRules($ruleType) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{enabled:bool,never_block_private:bool,exempt_ips:string[],retention_days:int} */
    public function settings(): array
    {
        $stored = [];
        try {
            $row = $this->policies->findByKey(self::SETTINGS_KEY);
            if ($row) {
                $decoded = json_decode((string) $row->value, true);
                $stored = is_array($decoded) ? $decoded : [];
            }
        } catch (\Throwable) {
        }
        $s = array_merge(self::SETTINGS_DEFAULTS, $stored);
        $s['enabled'] = (bool) $s['enabled'];
        $s['never_block_private'] = (bool) $s['never_block_private'];
        $s['exempt_ips'] = array_values(array_filter(array_map('trim', (array) $s['exempt_ips'])));
        $s['retention_days'] = max(1, min(365, (int) $s['retention_days']));
        return $s;
    }

    /** @throws \InvalidArgumentException */
    public function updateSettings(array $in, $adminId): array
    {
        $cur = $this->settings();
        $exempt = $in['exempt_ips'] ?? $cur['exempt_ips'];
        if (is_string($exempt)) {
            $exempt = preg_split('/[\s,;]+/', $exempt, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $exempt = array_values(array_unique(array_map('trim', (array) $exempt)));
        foreach ($exempt as $e) {
            if (!$this->validEntry($e)) {
                throw new \InvalidArgumentException("Invalid IP/CIDR entry: {$e}");
            }
        }
        if (count($exempt) > 500) {
            throw new \InvalidArgumentException('Too many exempt entries (max 500).');
        }

        $next = [
            'enabled'             => array_key_exists('enabled', $in) ? filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN) : $cur['enabled'],
            'never_block_private' => array_key_exists('never_block_private', $in) ? filter_var($in['never_block_private'], FILTER_VALIDATE_BOOLEAN) : $cur['never_block_private'],
            'exempt_ips'          => $exempt,
            'retention_days'      => max(1, min(365, (int) ($in['retention_days'] ?? $cur['retention_days']))),
        ];

        if (!$this->policies->updateValue(self::SETTINGS_KEY, json_encode($next, JSON_UNESCAPED_UNICODE), $adminId)) {
            throw new \RuntimeException('Could not save the detection settings.');
        }
        return $next;
    }

    // ---------------------------------------------------------------- ingestion

    /**
     * نقطة الدخول الوحيدة. $ctx: user_id, ip, identifier (email)، country (label)، device_hash، bytes، meta[]
     * بتسجّل الحدث وبعدين بتقيّم القواعد المفعّلة بتاعته.
     */
    public function record(string $event, array $ctx = []): void
    {
        try {
            if (!$this->available()) {
                return;
            }
            $settings = $this->settings();
            if (!$settings['enabled']) {
                return;
            }

            $ip = isset($ctx['ip']) ? (string) $ctx['ip'] : null;
            $userId = isset($ctx['user_id']) ? (int) $ctx['user_id'] : null;
            $identifier = isset($ctx['identifier']) ? mb_strtolower(trim((string) $ctx['identifier'])) : null;

            // Baseline: بنتعلّم دايمًا (حتى لو القاعدة مقفولة) عشان لما تتفعّل ما تغرقش الأدمن بكل المعروف.
            $learned = ['country' => null, 'device' => null];
            if ($event === 'login_success' && $userId) {
                $learned['country'] = $this->learn($userId, 'country', $this->cleanCountry($ctx['country'] ?? null));
                $learned['device'] = $this->learn($userId, 'device', !empty($ctx['device_hash']) ? (string) $ctx['device_hash'] : null);
            }

            // الأحداث اللي بنعدّها (مش role_granted/login_success اللي مفيهمش window).
            if (in_array($event, ['failed_login', 'file_download', 'login_success', 'role_granted', 'attack_probe', 'path_probe', 'data_export', 'data_delete', 'data_bulk_update', 'config_change'], true)) {
                $row = [
                    'event_type' => $event,
                    'user_id'    => $userId ?: null,
                    'ip_address' => $ip ? mb_substr($ip, 0, 45) : null,
                    'identifier' => $identifier ? mb_substr($identifier, 0, 190) : null,
                    'country'    => isset($ctx['country']) ? mb_substr((string) $ctx['country'], 0, 120) : null,
                    'bytes'      => isset($ctx['bytes']) ? max(0, (int) $ctx['bytes']) : null,
                    'meta'       => !empty($ctx['meta']) ? json_encode($ctx['meta'], JSON_UNESCAPED_UNICODE) : null,
                    'created_at' => now(),
                ];
                if (isset($ctx['amount']) && $this->hasAmountColumn()) {
                    $row['amount'] = max(1, (int) $ctx['amount']);
                }
                DB::table('security_detection_events')->insert($row);
            }

            foreach ($this->rulesForEvent($event) as $rule) {
                try {
                    $def = self::TYPES[$rule->rule_type] ?? null;
                    if (!$def) {
                        continue;
                    }
                    if (isset($def['baseline'])) {
                        $this->evaluateBaseline($rule, $def, $ctx, $learned[$def['baseline']] ?? null);
                    } elseif ($rule->rule_type === 'privilege_escalation') {
                        $this->evaluatePrivilege($rule, $def, $ctx);
                    } elseif (($def['subject'] ?? null) === 'global') {
                        $this->evaluateGlobal($rule, $def);
                    } else {
                        $this->evaluateWindowed($rule, $def, $ctx);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Detection rule failed: ' . $e->getMessage(), ['rule' => $rule->rule_key ?? null]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Detection engine error: ' . $e->getMessage(), ['event' => $event]);
        }
    }

    // ---------------------------------------------------------------- evaluation

    private function evaluateWindowed(object $rule, array $def, array $ctx): void
    {
        $subject = $def['subject'];
        $value = match ($subject) {
            'ip'         => $ctx['ip'] ?? null,
            'identifier' => isset($ctx['identifier']) ? mb_strtolower(trim((string) $ctx['identifier'])) : null,
            'user'       => $ctx['user_id'] ?? null,
            default      => null,
        };
        if ($value === null || $value === '') {
            return;
        }
        if ($subject === 'ip' && $this->isExempt((string) $value, false)) {
            return; // IP معفي من الكشف نفسه (مختلف عن "معفي من الحظر التلقائي بس")
        }

        $col = ['ip' => 'ip_address', 'identifier' => 'identifier', 'user' => 'user_id'][$subject];
        $window = max(1, (int) $rule->window_minutes);

        $q = DB::table('security_detection_events')
            ->where('event_type', $def['event'])
            ->where($col, $value)
            ->where('created_at', '>=', now()->subMinutes($window));

        if (isset($def['distinct'])) {
            $observed = (int) (clone $q)->whereNotNull($def['distinct'])->distinct()->count($def['distinct']);
        } elseif (isset($def['sum']) && $this->hasAmountColumn()) {
            // حذف/تعديل جماعي: كل حدث ليه amount (عدد العناصر)؛ الحدث من غير amount بيعدّ 1.
            $observed = (int) (clone $q)->sum(DB::raw('COALESCE(amount, 1)'));
        } else {
            $observed = (int) (clone $q)->count();
        }

        $config = $this->decode($rule->config);
        $bytesHit = false;
        if ($rule->rule_type === 'mass_download' && (int) ($config['max_mb'] ?? 0) > 0) {
            $mb = ((int) (clone $q)->sum('bytes')) / 1048576;
            $bytesHit = $mb >= (int) $config['max_mb'];
        }

        if ($observed < (int) $rule->threshold && !$bytesHit) {
            return;
        }

        $this->fire($rule, $def, $subject === 'user' ? 'user' : ($subject === 'ip' ? 'ip' : 'account'), (string) $value, $observed, $ctx, [
            'window_minutes' => $window,
            'megabytes'      => isset($mb) ? round($mb, 1) : null,
            'last'           => isset($ctx['meta']) && is_array($ctx['meta']) ? array_intersect_key($ctx['meta'], ['route' => 1, 'method' => 1, 'target' => 1]) : null,
        ]);
    }

    /**
     * Credential stuffing: عدد محاولات الدخول الفاشلة الكلي في الـ window + لازم تكون من عناوين وحسابات مختلفة كتير
     * (config.min_ips / config.min_accounts) — عشان زحمة طبيعية من IP واحد أو حساب واحد (بتغطيها القواعد التانية) مماتعدّش.
     * بتتقيّم بحد أقصى مرة كل 15 ثانية لكل قاعدة عشان هجوم كبير مايكلّفش 3 استعلامات على كل محاولة.
     */
    private function evaluateGlobal(object $rule, array $def): void
    {
        $gate = 'det:glob:' . \App\Services\Tenancy\TenantContext::ns() . $rule->id;
        if (!Cache::add($gate, 1, 15)) {
            return;
        }
        $window = max(1, (int) $rule->window_minutes);
        $q = DB::table('security_detection_events')
            ->where('event_type', $def['event'])
            ->where('created_at', '>=', now()->subMinutes($window));

        $observed = (int) (clone $q)->count();
        if ($observed < (int) $rule->threshold) {
            return;
        }
        $config = $this->decode($rule->config);
        $ips = (int) (clone $q)->whereNotNull('ip_address')->distinct()->count('ip_address');
        $accounts = (int) (clone $q)->whereNotNull('identifier')->distinct()->count('identifier');
        if ($ips < max(1, (int) ($config['min_ips'] ?? 5)) || $accounts < max(1, (int) ($config['min_accounts'] ?? 5))) {
            return;
        }

        // ctx فاضي: التنبيه عن الهجوم كله مش عن آخر IP/حساب.
        $this->fire($rule, $def, 'global', 'all', $observed, [], [
            'window_minutes' => $window, 'distinct_ips' => $ips, 'distinct_accounts' => $accounts,
        ]);
    }

    private function evaluateBaseline(object $rule, array $def, array $ctx, ?array $learn): void
    {
        $userId = (int) ($ctx['user_id'] ?? 0);
        if (!$userId || !$learn || empty($learn['is_new'])) {
            return;
        }
        $config = $this->decode($rule->config);
        // قاعدة مقصورة على أدوار معيّنة (مثلًا دخول من جهاز/بلد جديد للأدمن بس). فاضي = كل المستخدمين.
        $onlyRoles = array_values(array_filter((array) ($config['roles'] ?? [])));
        if ($onlyRoles && !$this->userHasAnyRole($userId, $onlyRoles)) {
            return;
        }
        $minKnown = max(1, (int) ($config['min_known'] ?? 1));
        if ((int) $learn['known_before'] < $minKnown) {
            return; // أول دخول/أول جهاز: مفيش "معروف" نقارن بيه
        }
        $this->fire($rule, $def, 'user', (string) $userId, 1, $ctx, [
            'kind' => $def['baseline'], 'value' => $learn['value'], 'previous' => $learn['previous'] ?? [],
        ]);
    }

    private function evaluatePrivilege(object $rule, array $def, array $ctx): void
    {
        $config = $this->decode($rule->config);
        $role = (string) ($ctx['meta']['role'] ?? '');
        $roles = (array) ($config['roles'] ?? ['admin', 'security_admin', 'security_officer']);
        if ($role === '' || !in_array($role, $roles, true) || empty($ctx['user_id'])) {
            return;
        }
        $this->fire($rule, $def, 'user', (string) $ctx['user_id'] . ':' . $role, 1, $ctx, [
            'role' => $role, 'granted_by' => $ctx['meta']['granted_by'] ?? null,
            'severity_override' => $config['severity_overrides'][$role] ?? null,
        ]);
    }

    // ---------------------------------------------------------------- firing

    private function fire(object $rule, array $def, string $subjectType, string $subjectValue, int $observed, array $ctx, array $extra): void
    {
        // Cooldown من جدول الـ hits (مش الكاش) عشان يصمد مع أكتر من instance.
        $recent = DB::table('security_detection_hits')
            ->where('rule_id', $rule->id)
            ->where('subject_value', mb_substr($subjectValue, 0, 190))
            ->where('created_at', '>=', now()->subMinutes(max(1, (int) $rule->cooldown_minutes)))
            ->exists();
        if ($recent) {
            return;
        }

        $actions = array_values(array_intersect((array) $this->decode($rule->actions), $def['actions']));
        $severity = in_array($extra['severity_override'] ?? null, self::SEVERITIES, true) ? $extra['severity_override'] : $rule->severity;
        $ip = isset($ctx['ip']) ? (string) $ctx['ip'] : null;
        $userId = isset($ctx['user_id']) ? (int) $ctx['user_id'] : null;
        $taken = [];

        if (in_array('block_ip', $actions, true)) {
            $targetIp = $subjectType === 'ip' ? $subjectValue : $ip;
            $taken[] = $this->actionBlockIp($rule, (string) $targetIp);
        }
        if (in_array('lock_account', $actions, true)) {
            $taken[] = $this->actionLockAccount($rule, $userId, $subjectType === 'account' ? $subjectValue : ($ctx['identifier'] ?? null));
        }
        if (in_array('revoke_sessions', $actions, true)) {
            $taken[] = $this->actionRevokeSessions($userId);
        }

        $alertId = null;
        $incidentId = null;
        [$title, $message] = $this->describe($rule, $subjectType, $subjectValue, $observed, $ctx, $extra, $taken);
        if (in_array('alert', $actions, true)) {
            $alertUser = $userId ?: ($subjectType === 'user' && ctype_digit(explode(':', $subjectValue)[0]) ? (int) explode(':', $subjectValue)[0] : null);
            // صحة السيرفر: النوع بيشيل الـ severity (health_disk_warning / health_disk_critical) عشان تنبيه "حرج" مايتدمجش
            // جوه تنبيه "تحذير" مفتوح لنفس المقياس (التجميع في raise() بيتم بالنوع).
            $alertId = app(SecurityAlertService::class)->raise(
                isset($def['health']) ? $def['alert_type'] . '_' . $severity : $def['alert_type'],
                $severity,
                $title,
                $message,
                $ip ?: ($subjectType === 'ip' ? $subjectValue : null),
                $alertUser,
                array_filter([
                    'rule_key'      => $rule->rule_key,
                    'rule_id'       => (int) $rule->id,
                    'observed'      => $observed,
                    'threshold'     => (int) $rule->threshold,
                    'window_minutes' => $extra['window_minutes'] ?? null,
                    'auto_actions'  => $taken ?: null,
                    'detail'        => array_diff_key($extra, ['window_minutes' => 1, 'severity_override' => 1]) ?: null,
                ], fn ($v) => $v !== null),
                max((int) $rule->cooldown_minutes, (int) $rule->window_minutes, 5)
            );
        }

        // فتح حادث (بيتربط بالتنبيه والحساب) ثم تنبيه المسؤولين (برقم الحادث لو اتفتح). كل واحد فيهم بيفشل لوحده من غير ما يوقف الباقي.
        if (in_array('open_incident', $actions, true)) {
            [$incidentId, $note] = $this->actionOpenIncident($rule, $def, (string) $severity, $subjectValue, $title, $message, $ip, $userId, $alertId, $taken);
            $taken[] = $note;
        }
        if (in_array('notify_admins', $actions, true)) {
            $taken[] = $this->actionNotifyAdmins((string) $severity, $title, $message, $incidentId, $alertId);
        }

        $hit = [
            'rule_id'       => $rule->id,
            'rule_key'      => $rule->rule_key,
            'subject_type'  => $subjectType,
            'subject_value' => mb_substr($subjectValue, 0, 190),
            'user_id'       => $userId ?: null,
            'ip_address'    => $ip ? mb_substr($ip, 0, 45) : null,
            'observed'      => $observed,
            'threshold'     => (int) $rule->threshold,
            'severity'      => $severity,
            'actions_taken' => json_encode($taken, JSON_UNESCAPED_UNICODE),
            'alert_id'      => $alertId,
            'created_at'    => now(),
        ];
        if ($this->hitsHaveIncidentColumn()) {
            $hit['incident_id'] = $incidentId;
        }
        DB::table('security_detection_hits')->insert($hit);

        DB::table('security_detection_rules')->where('id', $rule->id)->update([
            'trigger_count'     => DB::raw('trigger_count + 1'),
            'last_triggered_at' => now(),
        ]);
        self::$rulesMemo = [];

        SecurityLog::write('Detection rule triggered: ' . $rule->rule_key, [
            'rule' => $rule->rule_key, 'subject' => $subjectValue, 'observed' => $observed, 'actions' => $taken,
            'user_id' => $userId, 'ip' => $ip,
        ]);
    }

    /** @return array{0:string,1:string} */
    private function describe(object $rule, string $subjectType, string $subjectValue, int $observed, array $ctx, array $extra, array $taken): array
    {
        if (isset(self::TYPES[$rule->rule_type]['health'])) {
            return $this->describeHealth($rule, $extra);
        }
        $w = $extra['window_minutes'] ?? $rule->window_minutes;
        $email = (string) ($ctx['identifier'] ?? '');
        if ($email === '' && !empty($ctx['user_id'])) {
            $email = (string) DB::table('users')->where('id', (int) $ctx['user_id'])->value('email');
        }

        switch ($rule->rule_type) {
            case 'failed_login_ip':
                $title = 'Possible brute-force attack';
                $msg = "{$observed} failed sign-in attempts from {$subjectValue} in the last {$w} minutes.";
                break;
            case 'password_spray':
                $title = 'Password spraying detected';
                $msg = "{$subjectValue} failed to sign in to {$observed} different accounts within {$w} minutes.";
                break;
            case 'failed_login_user':
                $title = 'Account under repeated attack';
                $msg = "{$observed} failed sign-in attempts against {$subjectValue} within {$w} minutes (any source IP).";
                break;
            case 'attack_probe_ip':
                $title = 'Attack attempts detected';
                $msg = "{$observed} attack attempts (SQL injection / XSS / path traversal / scanner tools) from {$subjectValue} within {$w} minutes.";
                break;
            case 'path_scan':
                $title = 'Endpoint scanning detected';
                $msg = "{$subjectValue} sent {$observed} requests to non-existent endpoints within {$w} minutes (reconnaissance / scanning).";
                break;
            case 'credential_stuffing':
                $title = 'Possible credential stuffing';
                $msg = "{$observed} failed sign-ins within {$w} minutes from {$extra['distinct_ips']} different addresses against {$extra['distinct_accounts']} different accounts.";
                break;
            case 'mass_export':
                $title = 'Mass data export';
                $msg = ($email ?: "User #{$subjectValue}") . " ran {$observed} data exports within {$w} minutes" . $this->lastSuffix($extra) . '.';
                break;
            case 'mass_delete':
                $title = 'Mass deletion';
                $msg = ($email ?: "User #{$subjectValue}") . " deleted about {$observed} items within {$w} minutes" . $this->lastSuffix($extra) . '.';
                break;
            case 'mass_modify':
                $title = 'Mass modification';
                $msg = ($email ?: "User #{$subjectValue}") . " bulk-modified about {$observed} items within {$w} minutes" . $this->lastSuffix($extra) . '.';
                break;
            case 'sensitive_change':
                $title = 'Sensitive permission / configuration change';
                $msg = ($email ?: "User #{$subjectValue}") . " made {$observed} permission/security configuration change(s) within {$w} minutes" . $this->lastSuffix($extra) . '.';
                break;
            case 'mass_download':
                $title = 'Mass file download';
                $mb = !empty($extra['megabytes']) ? " (~{$extra['megabytes']} MB)" : '';
                $msg = ($email ?: "User #{$subjectValue}") . " downloaded {$observed} files within {$w} minutes{$mb}.";
                break;
            case 'new_country':
                $title = 'Sign-in from a new location';
                $prev = $extra['previous'] ? implode(', ', array_slice($extra['previous'], 0, 3)) : '—';
                $msg = ($email ?: "User #{$subjectValue}") . " signed in from {$extra['value']}; previous sign-ins were from {$prev}.";
                break;
            case 'new_device':
                $title = 'Sign-in from a new device';
                $msg = ($email ?: "User #{$subjectValue}") . ' signed in from a device that was not seen on this account before.';
                break;
            case 'privilege_escalation':
                $title = "Elevated role granted: {$extra['role']}";
                $by = !empty($extra['granted_by']) ? " by user #{$extra['granted_by']}" : '';
                $msg = ($email ?: 'An account') . " was granted the {$extra['role']} role{$by}.";
                break;
            default:
                $title = (string) $rule->name_en;
                $msg = "Rule {$rule->rule_key} reached {$observed} (threshold {$rule->threshold}).";
        }

        if ($taken) {
            $msg .= ' Automatic actions: ' . implode('; ', $taken) . '.';
        }
        return [$title, $msg . " [rule: {$rule->name_en}]"];
    }

    // ---------------------------------------------------------------- actions

    private function actionBlockIp(object $rule, string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return 'block_ip skipped (no valid IP)';
        }
        if ($this->isExempt($ip, true)) {
            return "block_ip skipped ({$ip} is exempt/private)";
        }
        $minutes = (int) $rule->block_minutes;
        $expires = $minutes > 0 ? now()->addMinutes($minutes) : null;
        $ok = $this->blocked->blockAuto($ip, 'Auto-block: ' . $rule->name_en, (int) $rule->id, $expires);
        if (!$ok) {
            return "block_ip skipped ({$ip} already blocked)";
        }
        return $minutes > 0 ? "blocked {$ip} for {$minutes} minutes" : "blocked {$ip} permanently";
    }

    private function actionLockAccount(object $rule, ?int $userId, $identifier): string
    {
        $user = null;
        if ($userId) {
            $user = DB::table('users')->where('id', $userId)->first();
        } elseif ($identifier) {
            $user = DB::table('users')->where('email', mb_strtolower((string) $identifier))->first();
        }
        if (!$user) {
            return 'lock_account skipped (no matching account)';
        }

        $isPrivileged = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)->whereIn('r.slug', ['admin', 'security_admin'])->exists();
        if ($isPrivileged) {
            return 'lock_account skipped (privileged account — alert only)';
        }

        $minutes = (int) $rule->block_minutes;
        DB::table('users')->where('id', $user->id)->update([
            'locked_until'   => $minutes > 0 ? now()->addMinutes($minutes) : null,
            'lock_permanent' => $minutes > 0 ? 0 : 1,
            'lock_reason'    => mb_substr('Detection rule: ' . $rule->name_en, 0, 250),
            'locked_at'      => now(),
        ]);
        Cache::forget('acct:status:' . \App\Services\Tenancy\TenantContext::ns() . $user->id);

        return $minutes > 0 ? "locked account #{$user->id} for {$minutes} minutes" : "locked account #{$user->id} permanently";
    }

    private static ?bool $hitsIncidentCol = null;

    private function hitsHaveIncidentColumn(): bool
    {
        if (self::$hitsIncidentCol === null) {
            try {
                self::$hitsIncidentCol = Schema::hasColumn('security_detection_hits', 'incident_id');
            } catch (\Throwable) {
                self::$hitsIncidentCol = false;
            }
        }
        return self::$hitsIncidentCol;
    }

    /**
     * فتح حادث تلقائي. لو نفس القاعدة فتحت حادث لنفس الـ subject وهو لسه مفتوح (open/investigating/contained)
     * بنضيف عليه حدث + تنبيه جديد بدل ما نفتح حادث تاني.
     *
     * @return array{0:?int,1:string} [incident id, نص يتسجّل في actions_taken]
     */
    private function actionOpenIncident(object $rule, array $def, string $severity, string $subjectValue, string $title, string $message, ?string $ip, ?int $userId, ?int $alertId, array $taken): array
    {
        try {
            $repo = app(\App\Repositories\SecurityIncidentRepository::class);

            if ($this->hitsHaveIncidentColumn()) {
                $open = DB::table('security_detection_hits as h')
                    ->join('security_incidents as i', 'i.id', '=', 'h.incident_id')
                    ->where('h.rule_id', $rule->id)
                    ->where('h.subject_value', mb_substr($subjectValue, 0, 190))
                    ->whereIn('i.status', ['open', 'investigating', 'contained'])
                    ->orderByDesc('h.id')->select('i.id', 'i.reference_code')->first();
                if ($open) {
                    $repo->addEvent((int) $open->id, null, 'auto_repeat', 'The detection rule "' . $rule->name_en . '" triggered again for the same source.');
                    if ($alertId) {
                        $repo->addLink((int) $open->id, 'alert', (string) $alertId, null);
                    }
                    return [(int) $open->id, "added to open incident {$open->reference_code}"];
                }
            }

            $incSeverity = self::INCIDENT_SEVERITY[$severity] ?? 'medium';
            $category = self::INCIDENT_CATEGORY[$rule->rule_type] ?? 'other';
            $affected = $userId && DB::table('users')->where('id', $userId)->exists() ? $userId : null;
            $desc = $message . ($taken ? "\n\nAutomatic actions: " . implode('; ', $taken) . '.' : '')
                . "\n\nOpened automatically by detection rule \"{$rule->name_en}\" ({$rule->rule_key}).";

            $incident = $repo->create([
                'title'            => mb_substr('[Auto] ' . $title, 0, 200),
                'description'      => $desc,
                'category'         => $category,
                'severity'         => $incSeverity,
                'source_ip'        => $ip && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
                'affected_user_id' => $affected,
                'created_by'       => null,
            ]);
            $repo->addEvent((int) $incident->id, null, 'created', 'Incident opened automatically by detection rule "' . $rule->name_en . '".');
            if ($alertId) {
                $repo->addLink((int) $incident->id, 'alert', (string) $alertId, null);
            }
            if ($affected) {
                $repo->addLink((int) $incident->id, 'user', (string) $affected, null);
            }

            return [(int) $incident->id, "opened incident {$incident->reference_code}"];
        } catch (\Throwable $e) {
            Log::warning('Detection open_incident failed: ' . $e->getMessage(), ['rule' => $rule->rule_key ?? null]);
            return [null, 'open_incident failed'];
        }
    }

    /** إشعار داخلي (جرس + push) لكل security_admin و admin نشطين. القنوات الخارجية بتتصعّد من EscalationService مع التنبيه/الحادث. */
    private function actionNotifyAdmins(string $severity, string $title, string $message, ?int $incidentId, ?int $alertId): string
    {
        try {
            $ids = DB::table('users as u')->join('user_roles as ur', 'ur.user_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->whereIn('r.slug', ['security_admin', 'admin'])->where('u.status', 'active')->whereNull('u.deleted_at')
                ->distinct()->limit(25)->pluck('u.id')->all();
            if (!$ids) {
                return 'notify_admins skipped (no active security administrators)';
            }
            $link = $incidentId ? '/security/incidents/' . $incidentId : ($alertId ? '/security/alerts' : '/security/intrusion-defense');
            $priority = in_array($severity, ['critical', 'high'], true) ? ($severity === 'critical' ? 'urgent' : 'high') : 'normal';
            $svc = app(NotificationService::class);
            foreach ($ids as $id) {
                $svc->notify((int) $id, 'security_intrusion', mb_substr($title, 0, 150), mb_substr($message, 0, 400), $link, $priority);
            }
            return 'notified ' . count($ids) . ' administrator(s)';
        } catch (\Throwable $e) {
            Log::warning('Detection notify_admins failed: ' . $e->getMessage());
            return 'notify_admins failed';
        }
    }

    private function actionRevokeSessions(?int $userId): string
    {
        if (!$userId) {
            return 'revoke_sessions skipped (no account)';
        }
        try {
            $res = app(\App\Services\Identity\TokenRevocationService::class)->revokeAll($userId, 'detection_rule');
            return "ended {$res['sessions']} session(s) of account #{$userId}";
        } catch (\Throwable $e) {
            Log::warning('Detection revoke failed: ' . $e->getMessage());
            return 'revoke_sessions failed';
        }
    }

    // ---------------------------------------------------------------- preview (dry-run)

    /**
     * "لو القاعدة دي كانت شغالة" — أعلى الـ subjects في الـ window من الأحداث المخزّنة، ومين كان هيعدّي الـ threshold.
     * مبتنفّذش أي إجراء. بتتنادى من شاشة التعديل لضبط الحد قبل الحفظ.
     *
     * @return array{supported:bool,rows:array<int,array<string,mixed>>,would_trigger:int,events_in_window:int}
     */
    public function preview(string $ruleType, int $threshold, int $windowMinutes): array
    {
        $def = self::TYPES[$ruleType] ?? null;
        if (!$def || !$def['windowed'] || isset($def['health']) || ($def['subject'] ?? null) === 'global' || !$this->available()) {
            return ['supported' => false, 'rows' => [], 'would_trigger' => 0, 'events_in_window' => 0];
        }
        $col = ['ip' => 'ip_address', 'identifier' => 'identifier', 'user' => 'user_id'][$def['subject']];
        $window = max(1, $windowMinutes);

        $base = DB::table('security_detection_events')
            ->where('event_type', $def['event'])->whereNotNull($col)
            ->where('created_at', '>=', now()->subMinutes($window));

        $measure = isset($def['distinct']) ? "COUNT(DISTINCT {$def['distinct']})" : (isset($def['sum']) && $this->hasAmountColumn() ? 'SUM(COALESCE(amount, 1))' : 'COUNT(*)');
        $rows = (clone $base)->selectRaw("{$col} as subject, {$measure} as observed")
            ->groupBy($col)->orderByDesc('observed')->limit(8)->get()
            ->map(fn ($r) => ['subject' => (string) $r->subject, 'observed' => (int) $r->observed, 'would_trigger' => (int) $r->observed >= $threshold])
            ->all();

        return [
            'supported'        => true,
            'rows'             => $rows,
            'would_trigger'    => count(array_filter($rows, fn ($r) => $r['would_trigger'])),
            'events_in_window' => (int) (clone $base)->count(),
        ];
    }

    // ---------------------------------------------------------------- server health (بند 2)

    /**
     * بيقيّم قواعد صحة السيرفر المفعّلة (cpu/ram/disk/db/queue/5xx + الارتفاعات المفاجئة). بيتنادى من `security:health-sample`
     * كل دقيقة بعد القياس. القيمة بتيجي من SecurityHealthService::observe()؛ لو فوق الـ threshold بنعدّي على نفس fire()
     * (cooldown + تنبيه مدموج + سجل hit) زي باقي القواعد. أي فشل في قاعدة بيتسجّل بس.
     */
    public function evaluateHealth(SecurityHealthService $health): void
    {
        if (!$this->available() || !$this->settings()['enabled']) {
            return;
        }
        foreach (self::TYPES as $type => $def) {
            if (!isset($def['health'])) {
                continue;
            }
            foreach ($this->enabledRules($type) as $rule) {
                try {
                    $obs = $health->observe($rule, $def);
                    if (!$obs || $obs['observed'] < (float) $rule->threshold) {
                        continue;
                    }
                    $this->fire($rule, $def, 'server', 'server', (int) round($obs['observed']), [], $obs['extra']);
                } catch (\Throwable $e) {
                    Log::warning('Health rule failed: ' . $e->getMessage(), ['rule' => $rule->rule_key ?? null]);
                }
            }
        }
    }

    /** @return array{0:string,1:string} */
    private function describeHealth(object $rule, array $extra): array
    {
        $w = (int) ($extra['window_minutes'] ?? $rule->window_minutes);
        $cur = $extra['current'] ?? null;
        $th = (int) $rule->threshold;
        $title = (string) $rule->name_en;

        switch ($rule->rule_type) {
            case 'server_cpu':
                $title = 'High CPU usage';
                $msg = "CPU averaged {$cur}% over the last {$w} minutes (alert level {$th}%).";
                break;
            case 'server_ram':
                $title = 'High memory usage';
                $msg = "Memory usage averaged {$cur}% over the last {$w} minutes (alert level {$th}%).";
                break;
            case 'server_disk':
                $title = 'Disk almost full';
                $msg = "Disk usage reached {$cur}% (alert level {$th}%). Free space or enlarge the volume before uploads and logs start failing.";
                break;
            case 'db_size':
                $title = 'Database size limit reached';
                $msg = "The database is {$cur} MB (limit {$th} MB).";
                break;
            case 'db_growth':
                $title = 'Database growing fast';
                $msg = "The database grew by {$cur} MB in the last {$w} minutes (alert level {$th} MB).";
                break;
            case 'queue_backlog':
                $title = 'Job queue backlog';
                $msg = "Up to {$cur} jobs were waiting in the queue during the last {$w} minutes (alert level {$th}). Check that the queue worker is running.";
                break;
            case 'queue_stalled':
                $title = 'Queue jobs waiting too long';
                $msg = "The oldest queued job has been waiting about {$cur} minutes (alert level {$th}). The queue worker may be stopped or overloaded.";
                break;
            case 'queue_failures':
                $title = 'Queue jobs failing';
                $msg = "{$cur} jobs failed in the last {$w} minutes (alert level {$th}). See the failed_jobs table.";
                break;
            case 'http_5xx':
                $title = 'Server errors (5xx)';
                $msg = "{$cur} requests ended with a 5xx server error in the last {$w} minutes (alert level {$th}).";
                break;
            default: // *_spike
                $map = [
                    'cpu_spike'      => ['Sudden CPU spike', 'CPU usage'],
                    'ram_spike'      => ['Sudden memory spike', 'Memory usage'],
                    'http_5xx_spike' => ['Sudden surge of 5xx errors', 'Server errors (5xx)'],
                    'queue_spike'    => ['Sudden queue growth', 'Queue size'],
                ];
                [$title, $what] = $map[$rule->rule_type] ?? [$title, 'The metric'];
                $base = $extra['baseline'] ?? '?';
                $bm = (int) ($extra['baseline_minutes'] ?? 60);
                $msg = "{$what} over the last {$w} minutes: {$cur}, versus {$base} over the previous {$bm} minutes (alert level: +{$th}% above normal).";
        }

        return [$title, $msg . " [rule: {$rule->name_en}]"];
    }

    // ---------------------------------------------------------------- maintenance

    /** @return array{events:int,blocks:int} */
    public function prune(?int $days = null): array
    {
        if (!$this->available()) {
            return ['events' => 0, 'blocks' => 0];
        }
        $days = $days ?: $this->settings()['retention_days'];
        $events = DB::table('security_detection_events')->where('created_at', '<', now()->subDays($days))->delete();

        $blocks = 0;
        try {
            $blocks = $this->blocked->purgeExpired();
        } catch (\Throwable) {
        }

        return ['events' => (int) $events, 'blocks' => (int) $blocks];
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<int,object> القواعد المفعّلة اللي بتتغذّى من الحدث ده. */
    private function rulesForEvent(string $event): array
    {
        $types = array_keys(array_filter(self::TYPES, fn ($d) => $d['event'] === $event));
        $out = [];
        foreach ($types as $type) {
            foreach ($this->enabledRules($type) as $r) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** @return array<int,object> */
    private function enabledRules(string $type): array
    {
        $key = \App\Services\Tenancy\TenantContext::ns() . $type;
        if (!isset(self::$rulesMemo[$key])) {
            self::$rulesMemo[$key] = DB::table('security_detection_rules')
                ->where('rule_type', $type)->where('enabled', 1)->orderBy('id')->get()->all();
        }
        return self::$rulesMemo[$key];
    }

    public static function forgetRulesCache(): void
    {
        self::$rulesMemo = [];
    }

    /** يسجّل القيمة في baseline المستخدم ويرجّع هل كانت جديدة. */
    private function learn(int $userId, string $kind, ?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = mb_substr($value, 0, 190);
        $known = DB::table('security_user_baselines')->where('user_id', $userId)->where('kind', $kind);
        $knownBefore = (int) (clone $known)->count();
        $row = (clone $known)->where('value', $value)->first();

        if ($row) {
            DB::table('security_user_baselines')->where('id', $row->id)->update([
                'seen_count' => DB::raw('seen_count + 1'), 'last_seen_at' => now(),
            ]);
            return ['is_new' => false, 'known_before' => $knownBefore, 'value' => $value];
        }

        $previous = (clone $known)->orderByDesc('last_seen_at')->limit(5)->pluck('value')->all();
        DB::table('security_user_baselines')->insertOrIgnore([
            'user_id' => $userId, 'kind' => $kind, 'value' => $value, 'seen_count' => 1,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        return ['is_new' => true, 'known_before' => $knownBefore, 'value' => $value, 'previous' => $kind === 'device' ? [] : $previous];
    }

    private static ?bool $amountCol = null;

    /** عمود amount اتضاف بـ migration بند 1 — الكود لازم يفضل شغال قبل الـ migration. */
    private function hasAmountColumn(): bool
    {
        if (self::$amountCol === null) {
            try {
                self::$amountCol = Schema::hasColumn('security_detection_events', 'amount');
            } catch (\Throwable) {
                self::$amountCol = false;
            }
        }
        return self::$amountCol;
    }

    /** @param string[] $slugs */
    private function userHasAnyRole(int $userId, array $slugs): bool
    {
        try {
            return DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $userId)->whereIn('r.slug', $slugs)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function lastSuffix(array $extra): string
    {
        $l = $extra['last'] ?? null;
        if (!is_array($l) || empty($l['route'])) {
            return '';
        }
        $t = !empty($l['target']) ? " #{$l['target']}" : '';
        return ' (last: ' . trim(($l['method'] ?? '') . ' /' . ltrim((string) $l['route'], '/')) . $t . ')';
    }

    private function cleanCountry($label): ?string
    {
        $label = trim((string) $label);
        return ($label === '' || $label === 'Local network') ? null : $label;
    }

    /** معفي من الحظر التلقائي (و$fully=false معناها معفي من الكشف نفسه: القايمة بس). */
    private function isExempt(string $ip, bool $forBlock): bool
    {
        $s = $this->settings();
        foreach ($s['exempt_ips'] as $entry) {
            if ($this->ipMatches($ip, $entry)) {
                return true;
            }
        }
        if ($forBlock && $s['never_block_private']
            && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }
        return false;
    }

    public function validEntry(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }
        if (!str_contains($entry, '/')) {
            return (bool) filter_var($entry, FILTER_VALIDATE_IP);
        }
        [$subnet, $bits] = explode('/', $entry, 2);
        return filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && ctype_digit($bits) && (int) $bits <= 32;
    }

    private function ipMatches(string $ip, string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }
        if (!str_contains($entry, '/')) {
            return $entry === $ip;
        }
        [$subnet, $bits] = explode('/', $entry, 2);
        $bits = (int) $bits;
        if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $bits < 0 || $bits > 32) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }

    /** @return array<string,mixed> */
    private function decode($v): array
    {
        if (is_array($v)) {
            return $v;
        }
        $d = json_decode((string) $v, true);
        return is_array($d) ? $d : [];
    }
}
