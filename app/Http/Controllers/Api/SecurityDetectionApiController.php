<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlockedIpRepository;
use App\Services\AuditLogService;
use App\Services\SecurityDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/security/detection/* — إدارة قواعد كشف التهديدات (بند 1).
 *
 * القراءة: security_admin / security_officer / admin. الكتابة (إنشاء/تعديل/حذف قواعد، الإعدادات، فك الحظر):
 * security_admin / admin بس (زي Policies). صلاحيات الـ permission بتتفحص في الـ routes، والدور هنا زي باقي كنترولرز الأمان.
 */
class SecurityDetectionApiController extends Controller
{
    private const HITS_PER_PAGE = 25;

    public function __construct(
        private SecurityDetectionService $engine,
        private BlockedIpRepository $blocked,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /rules — القواعد + الكتالوج + KPIs. */
    public function index(Request $request)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can view detection rules.', null, 403);
        }
        if (!$this->engine->available()) {
            return $this->apiError('Detection engine tables are missing. Run the database migrations.', null, 503);
        }

        $rules = DB::table('security_detection_rules')->orderByDesc('enabled')->orderBy('id')->get()
            ->map(fn ($r) => $this->present($r))->all();

        $since24 = now()->subDay();
        $kpis = [
            'rules_total'    => count($rules),
            'rules_enabled'  => count(array_filter($rules, fn ($r) => $r['enabled'])),
            'hits_24h'       => (int) DB::table('security_detection_hits')->where('created_at', '>=', $since24)->count(),
            'hits_7d'        => (int) DB::table('security_detection_hits')->where('created_at', '>=', now()->subDays(7))->count(),
            'active_blocks'  => count($this->blocked->activeAuto()),
            'events_24h'     => (int) DB::table('security_detection_events')->where('created_at', '>=', $since24)->count(),
        ];

        $types = [];
        foreach (SecurityDetectionService::TYPES as $key => $def) {
            $types[$key] = [
                'unit'     => $def['unit'],
                'windowed' => $def['windowed'],
                'actions'  => $def['actions'],
                'baseline' => $def['baseline'] ?? null,
                'health'   => isset($def['health']),
                'spike'    => !empty($def['spike']),
            ];
        }

        return $this->apiSuccess($rules, 'Detection rules retrieved successfully.', 200, [
            'kpis'       => $kpis,
            'types'      => $types,
            'severities' => SecurityDetectionService::SEVERITIES,
            'settings'   => $this->engine->settings(),
            'can_edit'   => $this->canEdit($request),
        ]);
    }

    /** POST /rules */
    public function store(Request $request)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only Security Administrators can create detection rules.', null, 403);
        }
        [$data, $errors] = $this->validated($request, null);
        if ($errors) {
            return $this->apiError(reset($errors)[0] ?? 'Invalid rule.', $errors, 422);
        }

        $uid = (int) $request->attributes->get('uip_user_id');
        $key = $this->uniqueKey((string) $data['name_en']);
        $id = DB::table('security_detection_rules')->insertGetId($data + [
            'rule_key' => $key, 'is_system' => 0, 'created_by' => $uid, 'updated_by' => $uid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        SecurityDetectionService::forgetRulesCache();
        $this->auditLog->record($uid, 'detection_rule.create', 'security_detection_rule', $id, null, $data, $request->ip());

        return $this->apiSuccess($this->present(DB::table('security_detection_rules')->find($id)), 'Rule created.', 201);
    }

    /** PATCH /rules/{id} — تعديل كامل أو toggle (enabled بس). */
    public function update(Request $request, $id)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only Security Administrators can edit detection rules.', null, 403);
        }
        $rule = DB::table('security_detection_rules')->find((int) $id);
        if (!$rule) {
            return $this->apiError('Rule not found.', null, 404);
        }

        $uid = (int) $request->attributes->get('uip_user_id');
        $before = $this->present($rule);

        // toggle سريع
        if ($request->has('enabled') && count($request->all()) === 1) {
            $enabled = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            DB::table('security_detection_rules')->where('id', $rule->id)->update(['enabled' => $enabled, 'updated_by' => $uid]);
            SecurityDetectionService::forgetRulesCache();
            $this->auditLog->record($uid, $enabled ? 'detection_rule.enable' : 'detection_rule.disable', 'security_detection_rule', $rule->id, null, null, $request->ip());
            return $this->apiSuccess($this->present(DB::table('security_detection_rules')->find($rule->id)), $enabled ? 'Rule enabled.' : 'Rule disabled.');
        }

        [$data, $errors] = $this->validated($request, $rule);
        if ($errors) {
            return $this->apiError(reset($errors)[0] ?? 'Invalid rule.', $errors, 422);
        }
        DB::table('security_detection_rules')->where('id', $rule->id)->update($data + ['updated_by' => $uid]);
        SecurityDetectionService::forgetRulesCache();
        $after = $this->present(DB::table('security_detection_rules')->find($rule->id));
        $this->auditLog->record($uid, 'detection_rule.update', 'security_detection_rule', $rule->id, $before, $after, $request->ip());

        return $this->apiSuccess($after, 'Rule updated.');
    }

    /** DELETE /rules/{id} — القواعد المدمجة (is_system) تتقفل بس، متتمسحش. */
    public function destroy(Request $request, $id)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only Security Administrators can delete detection rules.', null, 403);
        }
        $rule = DB::table('security_detection_rules')->find((int) $id);
        if (!$rule) {
            return $this->apiError('Rule not found.', null, 404);
        }
        if ($rule->is_system) {
            return $this->apiError('Built-in rules cannot be deleted — disable them instead.', null, 422);
        }
        DB::table('security_detection_rules')->where('id', $rule->id)->delete();
        SecurityDetectionService::forgetRulesCache();
        $this->auditLog->record((int) $request->attributes->get('uip_user_id'), 'detection_rule.delete', 'security_detection_rule', $rule->id, $this->present($rule), null, $request->ip());

        return $this->apiSuccess(null, 'Rule deleted.');
    }

    /** POST /preview { rule_type, threshold, window_minutes } — dry-run على الأحداث المخزّنة، من غير أي إجراء. */
    public function preview(Request $request)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can preview rules.', null, 403);
        }
        $type = (string) $request->input('rule_type', '');
        if (!isset(SecurityDetectionService::TYPES[$type])) {
            return $this->apiError('Unknown rule type.', null, 422);
        }

        return $this->apiSuccess($this->engine->preview(
            $type,
            max(1, (int) $request->input('threshold', 1)),
            max(1, min(10080, (int) $request->input('window_minutes', 10)))
        ), 'Preview computed.');
    }

    /** GET /hits?rule_id=&page= — آخر مرات التفعيل. */
    public function hits(Request $request)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can view detection hits.', null, 403);
        }
        $page = max(1, (int) $request->input('page', 1));
        $q = DB::table('security_detection_hits as h')
            ->leftJoin('security_detection_rules as r', 'r.id', '=', 'h.rule_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->when($request->filled('rule_id'), fn ($x) => $x->where('h.rule_id', (int) $request->input('rule_id')));

        $total = (clone $q)->count();
        $rows = $q->select('h.*', 'r.name_en as rule_name', 'r.name_ar as rule_name_ar', 'u.full_name as user_name', 'u.email as user_email')
            ->orderByDesc('h.id')->limit(self::HITS_PER_PAGE)->offset(($page - 1) * self::HITS_PER_PAGE)->get()
            ->map(function ($r) {
                $a = (array) $r;
                $a['actions_taken'] = json_decode((string) $r->actions_taken, true) ?: [];
                return $a;
            })->all();

        return $this->apiSuccess($rows, 'Detection hits retrieved successfully.', 200, [
            'page' => $page, 'perPage' => self::HITS_PER_PAGE, 'total' => $total,
        ]);
    }

    /** GET /blocks — الحظر التلقائي النشط. */
    public function blocks(Request $request)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can view blocks.', null, 403);
        }
        return $this->apiSuccess($this->blocked->activeAuto(), 'Active auto-blocks retrieved successfully.');
    }

    /** POST /blocks/{id}/unblock */
    public function unblock(Request $request, $id)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only Security Administrators can unblock IP addresses.', null, 403);
        }
        $row = DB::table('blocked_ips')->find((int) $id);
        $ok = $this->blocked->unblock((int) $id);
        if ($ok) {
            $this->auditLog->record((int) $request->attributes->get('uip_user_id'), 'ip.unblock', 'blocked_ip', (int) $id, ['ip' => $row->ip_address ?? null], null, $request->ip());
        }
        return $ok ? $this->apiSuccess(null, 'IP unblocked.') : $this->apiError('Could not unblock that IP.', null, 422);
    }

    /** GET /settings */
    public function settings(Request $request)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can view detection settings.', null, 403);
        }
        return $this->apiSuccess($this->engine->settings(), 'Detection settings retrieved successfully.');
    }

    /** PATCH /settings { enabled, never_block_private, exempt_ips[], retention_days } */
    public function updateSettings(Request $request)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only Security Administrators can change detection settings.', null, 403);
        }
        $uid = (int) $request->attributes->get('uip_user_id');
        $before = $this->engine->settings();
        try {
            $after = $this->engine->updateSettings($request->all(), $uid);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            return $this->apiError('Could not save the detection settings.', null, 500);
        }
        $this->auditLog->record($uid, 'detection_settings.update', 'SecurityPolicy', null, $before, $after, $request->ip());

        return $this->apiSuccess($after, 'Detection settings saved.');
    }

    // -- helpers --------------------------------------------------------------

    /** @return array{0:array<string,mixed>,1:array<string,array<int,string>>} */
    private function validated(Request $request, ?object $existing): array
    {
        $errors = [];
        $in = $request->all();

        // النوع ثابت بعد الإنشاء.
        $type = $existing ? (string) $existing->rule_type : (string) ($in['rule_type'] ?? '');
        $def = SecurityDetectionService::TYPES[$type] ?? null;
        if (!$def) {
            return [[], ['rule_type' => ['Choose a valid rule type.']]];
        }

        $nameEn = trim((string) ($in['name_en'] ?? ($existing->name_en ?? '')));
        if ($nameEn === '' || mb_strlen($nameEn) > 160) {
            $errors['name_en'] = ['Name is required (max 160 characters).'];
        }
        $nameAr = trim((string) ($in['name_ar'] ?? ($existing->name_ar ?? '')));
        if (mb_strlen($nameAr) > 160) {
            $errors['name_ar'] = ['Arabic name is too long (max 160).'];
        }

        $severity = (string) ($in['severity'] ?? ($existing->severity ?? 'warning'));
        if (!in_array($severity, SecurityDetectionService::SEVERITIES, true)) {
            $errors['severity'] = ['Invalid severity.'];
        }

        $threshold = (int) ($in['threshold'] ?? ($existing->threshold ?? 1));
        $window = (int) ($in['window_minutes'] ?? ($existing->window_minutes ?? 10));
        $cooldown = (int) ($in['cooldown_minutes'] ?? ($existing->cooldown_minutes ?? 30));
        $blockMinutes = (int) ($in['block_minutes'] ?? ($existing->block_minutes ?? 30));

        if ($def['windowed']) {
            // صحة السيرفر: الحد ممكن يبقى حجم DB بالميجا (مثلًا 5120) أو نسبة ارتفاع %، فالسقف أعلى.
            $maxThreshold = isset($def['health']) ? 10000000 : 100000;
            if ($threshold < 1 || $threshold > $maxThreshold) {
                $errors['threshold'] = ["Threshold must be between 1 and {$maxThreshold}."];
            }
            if ($window < 1 || $window > 10080) {
                $errors['window_minutes'] = ['Window must be between 1 minute and 7 days (10080).'];
            }
        } else {
            $threshold = 1;
            $window = 0;
        }
        if ($cooldown < 1 || $cooldown > 10080) {
            $errors['cooldown_minutes'] = ['Cool-down must be between 1 and 10080 minutes.'];
        }
        if ($blockMinutes < 0 || $blockMinutes > 525600) {
            $errors['block_minutes'] = ['Block duration must be 0 (permanent) to 525600 minutes.'];
        }

        $actions = $in['actions'] ?? (isset($existing) ? json_decode((string) $existing->actions, true) : ['alert']);
        $actions = array_values(array_unique(array_map('strval', (array) $actions)));
        if (!$actions || array_diff($actions, $def['actions'])) {
            $errors['actions'] = ['Pick at least one action, from the ones this rule type supports.'];
        }

        // config: whitelist بس.
        $cfgIn = is_array($in['config'] ?? null) ? $in['config'] : (isset($existing) ? (json_decode((string) $existing->config, true) ?: []) : []);
        $config = [];
        if ($type === 'mass_download') {
            $config['max_mb'] = max(0, min(1000000, (int) ($cfgIn['max_mb'] ?? 0)));
        }
        if (isset($def['baseline'])) {
            $config['min_known'] = max(1, min(50, (int) ($cfgIn['min_known'] ?? 1)));
            // مقصورة على أدوار معيّنة (فاضي = كل المستخدمين) — مثلًا دخول من جهاز/بلد جديد للأدمن بس.
            $config['roles'] = array_values(array_filter(array_map('strval', (array) ($cfgIn['roles'] ?? [])), fn ($r) => preg_match('/^[a-z0-9_\-]{2,60}$/', $r)));
        }
        if (!empty($def['spike'])) {
            // ارتفاع مفاجئ: أرضية مطلقة + طول فترة "الطبيعي" اللي بنقارن بيها.
            $config['min_value'] = max(0, min(1000000, (int) ($cfgIn['min_value'] ?? 0)));
            $config['baseline_minutes'] = max(10, min(1440, (int) ($cfgIn['baseline_minutes'] ?? 60)));
        }
        if ($type === 'credential_stuffing') {
            $config['min_ips'] = max(1, min(1000, (int) ($cfgIn['min_ips'] ?? 5)));
            $config['min_accounts'] = max(1, min(1000, (int) ($cfgIn['min_accounts'] ?? 5)));
        }
        if ($type === 'privilege_escalation') {
            $roles = array_values(array_filter(array_map('strval', (array) ($cfgIn['roles'] ?? ['admin', 'security_admin', 'security_officer'])), fn ($r) => preg_match('/^[a-z0-9_\-]{2,60}$/', $r)));
            if (!$roles) {
                $errors['config'] = ['Pick at least one role to watch.'];
            }
            $config['roles'] = $roles;
            $ov = [];
            foreach ((array) ($cfgIn['severity_overrides'] ?? []) as $role => $sev) {
                if (in_array($role, $roles, true) && in_array($sev, SecurityDetectionService::SEVERITIES, true)) {
                    $ov[$role] = $sev;
                }
            }
            $config['severity_overrides'] = $ov;
        }

        if ($errors) {
            return [[], $errors];
        }

        $data = [
            'name_en'          => $nameEn,
            'name_ar'          => $nameAr !== '' ? $nameAr : null,
            'description'      => isset($in['description']) ? mb_substr(trim((string) $in['description']), 0, 1000) : ($existing->description ?? null),
            'enabled'          => array_key_exists('enabled', $in) ? (filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : (int) ($existing->enabled ?? 1),
            'severity'         => $severity,
            'threshold'        => $threshold,
            'window_minutes'   => $window,
            'cooldown_minutes' => $cooldown,
            'actions'          => json_encode($actions),
            'block_minutes'    => $blockMinutes,
            'config'           => $config ? json_encode($config) : null,
        ];
        if (!$existing) {
            $data['rule_type'] = $type;
        }

        return [$data, []];
    }

    private function uniqueKey(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        $base = substr($base !== '' ? $base : 'rule', 0, 60);
        $key = $base;
        $i = 2;
        while (DB::table('security_detection_rules')->where('rule_key', $key)->exists()) {
            $key = $base . '_' . $i++;
        }
        return $key;
    }

    /** @return array<string,mixed> */
    private function present(object $r): array
    {
        $a = (array) $r;
        $a['enabled'] = (bool) $r->enabled;
        $a['is_system'] = (bool) $r->is_system;
        $a['actions'] = json_decode((string) $r->actions, true) ?: [];
        $a['config'] = json_decode((string) $r->config, true) ?: (object) [];
        return $a;
    }

    private function isStaff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }

    private function canEdit(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'admin'], true);
    }
}
