<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * قوائم الامتثال (بند 6): ISO/IEC 27001:2022 (Annex A — الضوابط ذات الصلة بالمنصة) + سياسة أمان الجامعة + ضوابط مخصصة.
 *
 * كل ضابط ليه حالة يدوية (not_started / in_progress / implemented / not_applicable) + مالك + دليل (ملاحظة/رابط) + موعد مراجعة.
 * ولو الضابط له auto_key، النظام بيفحص إعدادات المنصة الفعلية (سياسات، سلسلة التدقيق، مراجعة الصلاحيات...) ويعرض نتيجة
 * pass/warn/fail كدليل — النتيجة دي مساعدة وليست بديلًا عن اعتماد المسؤول (حالة "implemented" اليدوية هي اللي بتتحسب في النسبة،
 * ولو الضابط لسه not_started وفحصه pass بيتحسب "مثبت آليًا").
 */
class ComplianceService
{
    public const FRAMEWORKS = ['ISO27001', 'UNI', 'CUSTOM'];
    public const STATUSES = ['not_started', 'in_progress', 'implemented', 'not_applicable'];

    /** [framework, code, category, title_en, title_ar, auto_key] */
    private const CATALOG = [
        ['ISO27001', 'A.5.1', 'Organizational', 'Policies for information security', 'سياسات أمن المعلومات', 'policies_defined'],
        ['ISO27001', 'A.5.2', 'Organizational', 'Information security roles and responsibilities', 'أدوار ومسؤوليات أمن المعلومات', null],
        ['ISO27001', 'A.5.3', 'Organizational', 'Segregation of duties', 'فصل المهام', 'role_separation'],
        ['ISO27001', 'A.5.15', 'Organizational', 'Access control', 'التحكم في الوصول', 'ip_policy'],
        ['ISO27001', 'A.5.16', 'Organizational', 'Identity management', 'إدارة الهوية', null],
        ['ISO27001', 'A.5.17', 'Organizational', 'Authentication information', 'معلومات المصادقة (كلمات المرور)', 'password_policy'],
        ['ISO27001', 'A.5.18', 'Organizational', 'Access rights (periodic review)', 'حقوق الوصول (مراجعة دورية)', 'access_review'],
        ['ISO27001', 'A.5.24', 'Organizational', 'Incident management planning and preparation', 'التخطيط لإدارة الحوادث والاستعداد لها', null],
        ['ISO27001', 'A.5.25', 'Organizational', 'Assessment and decision on security events', 'تقييم أحداث الأمان واتخاذ القرار', 'detection_rules'],
        ['ISO27001', 'A.5.26', 'Organizational', 'Response to information security incidents', 'الاستجابة لحوادث أمن المعلومات', 'incident_response'],
        ['ISO27001', 'A.5.28', 'Organizational', 'Collection of evidence', 'جمع الأدلة', 'audit_chain'],
        ['ISO27001', 'A.5.34', 'Organizational', 'Privacy and protection of personal information', 'الخصوصية وحماية البيانات الشخصية', null],
        ['ISO27001', 'A.5.36', 'Organizational', 'Compliance with policies and standards', 'الالتزام بالسياسات والمعايير', null],
        ['ISO27001', 'A.6.3', 'People', 'Information security awareness and training', 'التوعية والتدريب على أمن المعلومات', null],
        ['ISO27001', 'A.8.2', 'Technological', 'Privileged access rights', 'صلاحيات الوصول المميّزة', 'mfa_privileged'],
        ['ISO27001', 'A.8.3', 'Technological', 'Information access restriction', 'تقييد الوصول للمعلومات', 'role_permissions'],
        ['ISO27001', 'A.8.5', 'Technological', 'Secure authentication', 'المصادقة الآمنة', 'lockout_policy'],
        ['ISO27001', 'A.8.7', 'Technological', 'Protection against malware (uploads)', 'الحماية من البرمجيات الخبيثة (الرفع)', 'upload_policy'],
        ['ISO27001', 'A.8.8', 'Technological', 'Management of technical vulnerabilities', 'إدارة الثغرات التقنية', 'vuln_overdue'],
        ['ISO27001', 'A.8.15', 'Technological', 'Logging', 'تسجيل الأحداث (Logging)', 'audit_chain'],
        ['ISO27001', 'A.8.16', 'Technological', 'Monitoring activities', 'مراقبة الأنشطة', 'detection_rules'],
        ['ISO27001', 'A.8.20', 'Technological', 'Networks security', 'أمن الشبكات', 'rate_limit'],
        ['ISO27001', 'A.8.24', 'Technological', 'Use of cryptography', 'استخدام التشفير', null],
        ['ISO27001', 'A.8.34', 'Technological', 'Protection of information systems during audit testing', 'حماية الأنظمة أثناء اختبارات التدقيق', null],
        ['ISO27001', 'A.5.33', 'Organizational', 'Protection of records (retention)', 'حماية السجلات (الاحتفاظ)', 'audit_retention'],

        ['UNI', 'UNI-01', 'Governance', 'Information security policy approved by university management', 'سياسة أمن المعلومات معتمدة من إدارة الجامعة', null],
        ['UNI', 'UNI-02', 'Governance', 'Security officer / team appointed', 'تعيين مسؤول/فريق أمن المعلومات', 'security_staff'],
        ['UNI', 'UNI-03', 'Access', 'Multi-factor authentication enforced for admins and security staff', 'تفعيل المصادقة الثنائية للأدمن وفريق الأمن', 'mfa_privileged'],
        ['UNI', 'UNI-04', 'Access', 'Admin accounts all have 2FA enabled', 'كل حسابات الأدمن مفعّل عليها 2FA', 'admin_2fa'],
        ['UNI', 'UNI-05', 'Access', 'Session timeout configured', 'ضبط مهلة انتهاء الجلسة', 'session_policy'],
        ['UNI', 'UNI-06', 'Data', 'Student data retention schedule defined', 'جدول الاحتفاظ ببيانات الطلاب محدد', 'audit_retention'],
        ['UNI', 'UNI-07', 'Data', 'Exam integrity monitoring', 'مراقبة سلامة الامتحانات', null],
        ['UNI', 'UNI-08', 'Operations', 'Security incidents tracked with SLA', 'تتبع حوادث الأمان بمهلة SLA', 'incident_response'],
        ['UNI', 'UNI-09', 'Operations', 'Periodic access review completed', 'إتمام مراجعة الصلاحيات الدورية', 'access_review'],
        ['UNI', 'UNI-10', 'Operations', 'Audit log integrity verified regularly', 'التحقق الدوري من سلامة سجل التدقيق', 'audit_chain'],
    ];

    public function __construct(
        private ComplianceSettings $settings,
        private AuditChainService $chain,
        private AccessReviewService $reviews,
        private AuditLogService $auditLog
    ) {
    }

    /** يضيف أي ضابط ناقص من الكتالوج (idempotent) — بيتنادى قبل أي قراءة. */
    public function ensureCatalog(): void
    {
        $existing = DB::table('compliance_controls')->where('is_custom', 0)->get(['framework', 'code'])
            ->map(fn ($r) => $r->framework . '|' . $r->code)->flip();
        $sort = 0;
        foreach (self::CATALOG as [$fw, $code, $cat, $en, $ar, $auto]) {
            $sort++;
            if (isset($existing[$fw . '|' . $code])) {
                continue;
            }
            $id = DB::table('compliance_controls')->insertGetId([
                'framework' => $fw, 'code' => $code, 'category' => $cat, 'title_en' => $en, 'title_ar' => $ar,
                'auto_key' => $auto, 'is_custom' => 0, 'sort' => $sort, 'created_at' => now(),
            ]);
            DB::table('compliance_control_status')->insertOrIgnore(['control_id' => $id, 'status' => 'not_started', 'updated_at' => now()]);
        }
    }

    /** @return array<string,mixed> */
    public function overview(?string $framework = null): array
    {
        $this->ensureCatalog();
        $auto = $this->autoChecks();
        $q = DB::table('compliance_controls as c')->leftJoin('compliance_control_status as s', 's.control_id', '=', 'c.id')
            ->leftJoin('users as u', 'u.id', '=', 's.reviewed_by')
            ->orderBy('c.framework')->orderBy('c.sort')->orderBy('c.code')
            ->select('c.*', 's.status', 's.owner', 's.evidence_note', 's.evidence_url', 's.reviewed_at', 's.next_review_at', 'u.full_name as reviewed_by_name');
        if ($framework && in_array($framework, self::FRAMEWORKS, true)) {
            $q->where('c.framework', $framework);
        }
        $today = date('Y-m-d');
        $controls = [];
        $sum = [];
        foreach ($q->get() as $r) {
            $status = $r->status ?: 'not_started';
            $a = $r->auto_key ? ($auto[$r->auto_key] ?? ['state' => 'unknown', 'detail' => '']) : null;
            $effective = $status;
            if ($status === 'not_started' && $a && $a['state'] === 'pass') {
                $effective = 'auto_pass';
            }
            $due = $r->next_review_at && $r->next_review_at < $today && $status === 'implemented';
            $controls[] = [
                'id' => (int) $r->id, 'framework' => $r->framework, 'code' => $r->code, 'category' => $r->category,
                'title_en' => $r->title_en, 'title_ar' => $r->title_ar, 'is_custom' => (bool) $r->is_custom,
                'status' => $status, 'effective' => $effective, 'owner' => $r->owner,
                'evidence_note' => $r->evidence_note, 'evidence_url' => $r->evidence_url,
                'reviewed_at' => $r->reviewed_at, 'reviewed_by_name' => $r->reviewed_by_name,
                'next_review_at' => $r->next_review_at, 'review_overdue' => $due, 'auto' => $a,
            ];
            $f = $r->framework;
            $sum[$f] ??= ['framework' => $f, 'total' => 0, 'implemented' => 0, 'in_progress' => 0, 'not_started' => 0, 'not_applicable' => 0, 'auto_pass' => 0, 'auto_fail' => 0, 'review_overdue' => 0];
            $sum[$f]['total']++;
            $sum[$f][$status]++;
            if ($effective === 'auto_pass') {
                $sum[$f]['auto_pass']++;
            }
            if ($a && in_array($a['state'], ['fail', 'warn'], true) && $status !== 'not_applicable') {
                $sum[$f]['auto_fail']++;
            }
            if ($due) {
                $sum[$f]['review_overdue']++;
            }
        }
        foreach ($sum as &$s) {
            $applicable = $s['total'] - $s['not_applicable'];
            $s['score'] = $applicable > 0 ? (int) round((($s['implemented']) / $applicable) * 100) : 0;
            $s['score_with_auto'] = $applicable > 0 ? (int) round((($s['implemented'] + $s['auto_pass']) / $applicable) * 100) : 0;
        }
        unset($s);

        return ['controls' => $controls, 'summary' => array_values($sum), 'frameworks' => self::FRAMEWORKS];
    }

    /**
     * @param array<string,mixed> $in
     * @throws \InvalidArgumentException
     */
    public function updateControl(int $id, array $in, ?int $userId, ?string $ip = null): void
    {
        $ctl = DB::table('compliance_controls')->where('id', $id)->first();
        if (!$ctl) {
            throw new \InvalidArgumentException('Control not found.');
        }
        $cur = DB::table('compliance_control_status')->where('control_id', $id)->first();
        $status = (string) ($in['status'] ?? ($cur->status ?? 'not_started'));
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown status.');
        }
        $url = trim((string) ($in['evidence_url'] ?? ($cur->evidence_url ?? '')));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('Evidence link must start with http:// or https://.');
        }
        $next = trim((string) ($in['next_review_at'] ?? ($cur->next_review_at ?? '')));
        if ($next !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $next)) {
            throw new \InvalidArgumentException('Next review date must be YYYY-MM-DD.');
        }
        $note = trim((string) ($in['evidence_note'] ?? ($cur->evidence_note ?? '')));
        if ($status === 'implemented' && $note === '' && $url === '' && !$ctl->auto_key) {
            throw new \InvalidArgumentException('Add an evidence note or link before marking a control implemented.');
        }
        $new = [
            'status' => $status, 'owner' => mb_substr(trim((string) ($in['owner'] ?? ($cur->owner ?? ''))), 0, 120) ?: null,
            'evidence_note' => $note !== '' ? mb_substr($note, 0, 2000) : null, 'evidence_url' => $url !== '' ? mb_substr($url, 0, 500) : null,
            'next_review_at' => $next !== '' ? $next : null, 'reviewed_by' => $userId, 'reviewed_at' => now(), 'updated_at' => now(),
        ];
        DB::table('compliance_control_status')->updateOrInsert(['control_id' => $id], $new);
        $this->auditLog->record($userId, 'compliance.control.updated', 'ComplianceControl', $id,
            ['status' => $cur->status ?? null], ['status' => $status, 'code' => $ctl->framework . ' ' . $ctl->code], $ip);
    }

    /**
     * @param array<string,mixed> $in
     * @throws \InvalidArgumentException
     */
    public function addControl(array $in, ?int $userId, ?string $ip = null): int
    {
        $fw = (string) ($in['framework'] ?? 'CUSTOM');
        if (!in_array($fw, ['UNI', 'CUSTOM'], true)) {
            throw new \InvalidArgumentException('Custom controls can only be added to the University policy or Custom lists.');
        }
        $code = trim((string) ($in['code'] ?? ''));
        $en = trim((string) ($in['title_en'] ?? ''));
        $ar = trim((string) ($in['title_ar'] ?? ''));
        if ($code === '' || mb_strlen($code) > 24 || ($en === '' && $ar === '')) {
            throw new \InvalidArgumentException('A control needs a code (max 24 chars) and a title.');
        }
        if (DB::table('compliance_controls')->where('framework', $fw)->where('code', $code)->exists()) {
            throw new \InvalidArgumentException('A control with this code already exists in this list.');
        }
        $id = DB::table('compliance_controls')->insertGetId([
            'framework' => $fw, 'code' => $code, 'category' => mb_substr(trim((string) ($in['category'] ?? 'Custom')), 0, 60) ?: 'Custom',
            'title_en' => mb_substr($en !== '' ? $en : $ar, 0, 255), 'title_ar' => mb_substr($ar !== '' ? $ar : $en, 0, 255),
            'is_custom' => 1, 'sort' => 1000, 'created_by' => $userId, 'created_at' => now(),
        ]);
        DB::table('compliance_control_status')->insert(['control_id' => $id, 'status' => 'not_started', 'updated_at' => now()]);
        $this->auditLog->record($userId, 'compliance.control.created', 'ComplianceControl', $id, null, ['framework' => $fw, 'code' => $code], $ip);

        return $id;
    }

    /** @throws \InvalidArgumentException */
    public function deleteControl(int $id, ?int $userId, ?string $ip = null): void
    {
        $ctl = DB::table('compliance_controls')->where('id', $id)->first();
        if (!$ctl || !$ctl->is_custom) {
            throw new \InvalidArgumentException('Only custom controls can be deleted.');
        }
        DB::table('compliance_controls')->where('id', $id)->delete();
        $this->auditLog->record($userId, 'compliance.control.deleted', 'ComplianceControl', $id, ['code' => $ctl->framework . ' ' . $ctl->code], null, $ip);
    }

    /** @return array<int,array<string,mixed>> */
    public function exportRows(?string $framework = null): array
    {
        $out = [];
        foreach ($this->overview($framework)['controls'] as $c) {
            $out[] = [
                'framework' => $c['framework'], 'code' => $c['code'], 'category' => $c['category'], 'title' => $c['title_en'], 'title_ar' => $c['title_ar'],
                'status' => $c['status'], 'auto_check' => $c['auto']['state'] ?? '', 'auto_detail' => $c['auto']['detail'] ?? '',
                'owner' => $c['owner'], 'evidence_note' => $c['evidence_note'], 'evidence_url' => $c['evidence_url'],
                'reviewed_at' => $c['reviewed_at'], 'reviewed_by' => $c['reviewed_by_name'], 'next_review_at' => $c['next_review_at'],
            ];
        }

        return $out;
    }

    // ───────────────────────────── الفحوصات التلقائية ─────────────────────────────

    /** @return array<string,array{state:string,detail:string}> */
    public function autoChecks(): array
    {
        $checks = [
            'policies_defined'  => fn () => $this->chkPoliciesDefined(),
            'role_separation'   => fn () => $this->chkRoleSeparation(),
            'ip_policy'         => fn () => $this->chkIpPolicy(),
            'password_policy'   => fn () => $this->chkPassword(),
            'access_review'     => fn () => $this->chkAccessReview(),
            'detection_rules'   => fn () => $this->chkDetection(),
            'incident_response' => fn () => $this->chkIncidents(),
            'audit_chain'       => fn () => $this->chkChain(),
            'mfa_privileged'    => fn () => $this->chkMfa(),
            'role_permissions'  => fn () => $this->chkRolePermissions(),
            'lockout_policy'    => fn () => $this->chkLockout(),
            'upload_policy'     => fn () => $this->chkUpload(),
            'vuln_overdue'      => fn () => $this->chkVulns(),
            'rate_limit'        => fn () => $this->chkRateLimit(),
            'audit_retention'   => fn () => $this->chkRetention(),
            'security_staff'    => fn () => $this->chkSecurityStaff(),
            'admin_2fa'         => fn () => $this->chkAdmin2fa(),
            'session_policy'    => fn () => $this->chkSession(),
        ];
        $out = [];
        foreach ($checks as $key => $fn) {
            try {
                $out[$key] = $fn();
            } catch (\Throwable $e) {
                $out[$key] = ['state' => 'unknown', 'detail' => 'Check could not run.'];
            }
        }

        return $out;
    }

    private function r(string $state, string $detail): array
    {
        return ['state' => $state, 'detail' => $detail];
    }

    private function policyJson(string $key): array
    {
        $row = DB::table('security_policies')->where('policy_key', $key)->first(['value']);
        $d = $row ? json_decode((string) $row->value, true) : null;

        return is_array($d) ? $d : [];
    }

    private function chkPoliciesDefined(): array
    {
        $n = (int) DB::table('security_policies')->where('is_active', 1)->count();

        return $n >= 5 ? $this->r('pass', "{$n} active security policies are configured.") : $this->r('warn', "Only {$n} active security policies are configured.");
    }

    private function chkRoleSeparation(): array
    {
        $both = DB::table('user_roles as a')->join('roles as ra', 'ra.id', '=', 'a.role_id')
            ->join('user_roles as b', 'b.user_id', '=', 'a.user_id')->join('roles as rb', 'rb.id', '=', 'b.role_id')
            ->where('ra.slug', 'admin')->where('rb.slug', 'security_officer')->distinct()->count('a.user_id');

        return $both === 0 ? $this->r('pass', 'No account combines admin and security officer roles.') : $this->r('warn', "{$both} account(s) hold both admin and security officer roles.");
    }

    private function chkIpPolicy(): array
    {
        $p = $this->policyJson('ip_restriction.policy');
        $mode = (string) ($p['mode'] ?? 'disabled');
        $blocks = \Illuminate\Support\Facades\Schema::hasTable('blocked_ips') ? (int) DB::table('blocked_ips')->count() : 0;

        return $mode !== 'disabled' || $blocks > 0
            ? $this->r('pass', $mode !== 'disabled' ? "IP restriction mode: {$mode}." : 'IP blocking is in use.')
            : $this->r('warn', 'IP restriction is disabled and no IPs are blocked.');
    }

    private function chkPassword(): array
    {
        $p = array_merge(['min_length' => 8, 'require_upper' => true, 'require_lower' => true, 'require_number' => true, 'require_special' => true, 'expire_days' => 0], $this->policyJson('password.policy'));
        $classes = (int) !empty($p['require_upper']) + (int) !empty($p['require_lower']) + (int) !empty($p['require_number']) + (int) !empty($p['require_special']);
        if ((int) $p['min_length'] >= 10 && $classes >= 3) {
            return $this->r('pass', "Min length {$p['min_length']}, {$classes} character classes required.");
        }

        return $this->r((int) $p['min_length'] >= 8 && $classes >= 3 ? 'warn' : 'fail', "Min length {$p['min_length']}, {$classes} character classes — recommended: 10+ characters and 3+ classes.");
    }

    private function chkAccessReview(): array
    {
        $s = $this->reviews->state();
        if ($s['open_review']) {
            return $this->r('warn', 'An access review is open and not completed yet.');
        }
        if ($s['last_completed_at'] === null) {
            return $this->r('fail', 'No access review has ever been completed.');
        }

        return $s['overdue'] ? $this->r('fail', 'The periodic access review is overdue (due ' . $s['next_due'] . ').') : $this->r('pass', 'Last access review completed ' . substr((string) $s['last_completed_at'], 0, 10) . '.');
    }

    private function chkDetection(): array
    {
        $n = (int) DB::table('security_detection_rules')->where('enabled', 1)->count();

        return $n > 0 ? $this->r('pass', "{$n} detection rule(s) enabled.") : $this->r('fail', 'No threat detection rule is enabled.');
    }

    private function chkIncidents(): array
    {
        $open = (int) DB::table('security_incidents')->whereNotIn('status', ['resolved', 'closed'])->count();
        $stale = (int) DB::table('security_incidents')->whereNotIn('status', ['resolved', 'closed'])
            ->whereIn('severity', ['high', 'critical'])->where('detected_at', '<', now()->subDays(14))->count();
        $total = (int) DB::table('security_incidents')->count();

        if ($stale > 0) {
            return $this->r('fail', "{$stale} high/critical incident(s) have been open for more than 14 days.");
        }

        return $this->r('pass', "{$total} incident(s) tracked, {$open} still open, none high/critical older than 14 days.");
    }

    private function chkChain(): array
    {
        $st = $this->chain->status();
        if ($st['sealed_rows'] === 0) {
            return $this->r('fail', 'The audit log chain has not been initialised yet.');
        }
        $v = $st['last_verification'];
        if (!$v) {
            return $this->r('warn', 'The audit chain has never been verified.');
        }
        if ($v['status'] === 'broken') {
            return $this->r('fail', 'The last verification found ' . $v['issues_total'] . ' integrity issue(s).');
        }
        $age = time() - strtotime((string) $v['created_at']);
        if ($age > 8 * 86400) {
            return $this->r('warn', 'The last successful verification is older than 7 days.');
        }
        if ($st['unsealed_rows'] > 0) {
            return $this->r('warn', $st['unsealed_rows'] . ' audit entries are not sealed yet.');
        }

        return $this->r('pass', 'Chain verified ' . substr((string) $v['created_at'], 0, 16) . '; ' . $st['sealed_rows'] . ' entries sealed.');
    }

    private function chkMfa(): array
    {
        $p = $this->policyJson('mfa.policy');
        $enforced = (array) ($p['enforced_roles'] ?? []);
        $need = ['admin', 'security_admin', 'security_officer'];
        $missing = array_values(array_diff($need, $enforced));

        return $missing === [] ? $this->r('pass', 'MFA is enforced for admin and security roles.') : $this->r($enforced ? 'warn' : 'fail', 'MFA is not enforced for: ' . implode(', ', $missing) . '.');
    }

    private function chkRolePermissions(): array
    {
        $roles = (int) DB::table('roles')->count();
        $perms = (int) DB::table('permissions')->count();

        return $roles > 1 && $perms > 0 ? $this->r('pass', "{$roles} roles and {$perms} permissions define who can do what.") : $this->r('fail', 'Role-based permissions are not configured.');
    }

    private function chkLockout(): array
    {
        $p = array_merge(['max_attempts' => 5], $this->policyJson('login.lockout_policy'));
        $m = (int) $p['max_attempts'];

        return $m >= 1 && $m <= 10 ? $this->r('pass', "Accounts lock after {$m} failed attempts.") : $this->r('warn', "Lockout threshold is {$m} attempts (recommended 3–10).");
    }

    private function chkUpload(): array
    {
        $p = $this->policyJson('upload.policy');

        return $p ? $this->r('pass', 'A file upload policy is configured.') : $this->r('warn', 'No explicit file upload policy is saved (defaults apply).');
    }

    private function chkVulns(): array
    {
        $n = (int) DB::table('vulnerabilities')->whereIn('status', ['open', 'in_progress'])->whereNotNull('deadline')->where('deadline', '<', date('Y-m-d'))->count();
        $crit = (int) DB::table('vulnerabilities')->whereIn('status', ['open', 'in_progress'])->where('severity', 'critical')->count();
        if ($n === 0 && $crit === 0) {
            return $this->r('pass', 'No overdue vulnerabilities and no open critical ones.');
        }

        return $this->r($crit > 0 ? 'fail' : 'warn', "{$n} overdue vulnerabilit" . ($n === 1 ? 'y' : 'ies') . ", {$crit} open critical.");
    }

    private function chkRateLimit(): array
    {
        $p = array_merge(['enabled' => true], $this->policyJson('rate_limit.policy'));

        return !empty($p['enabled']) ? $this->r('pass', 'API rate limiting is enabled.') : $this->r('fail', 'API rate limiting is disabled.');
    }

    private function chkRetention(): array
    {
        $c = $this->settings->get('audit_retention');
        if (!empty($c['legal_hold'])) {
            return $this->r('pass', 'Retention is under legal hold (records preserved).');
        }

        return !empty($c['enabled'])
            ? $this->r('pass', "Audit logs kept {$c['audit_days']} days, security logs {$c['security_log_days']} days.")
            : $this->r('warn', 'No automatic retention schedule is enabled (logs are kept indefinitely).');
    }

    private function chkSecurityStaff(): array
    {
        $n = (int) DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->join('users as u', 'u.id', '=', 'ur.user_id')
            ->whereIn('r.slug', ['security_admin', 'security_officer'])->whereNull('u.deleted_at')->where('u.status', 'active')->distinct()->count('ur.user_id');

        return $n >= 1 ? $this->r('pass', "{$n} active security staff account(s).") : $this->r('fail', 'No active security staff account exists.');
    }

    private function chkAdmin2fa(): array
    {
        $rows = DB::table('users as u')->join('user_roles as ur', 'ur.user_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.slug', 'admin')->whereNull('u.deleted_at')->where('u.status', 'active')->distinct()->get(['u.id', 'u.two_factor_enabled']);
        $without = $rows->where('two_factor_enabled', 0)->count();

        if ($rows->isEmpty()) {
            return $this->r('unknown', 'No active admin accounts found.');
        }

        return $without === 0 ? $this->r('pass', 'All ' . $rows->count() . ' active admin accounts use 2FA.') : $this->r('fail', "{$without} of " . $rows->count() . ' active admin accounts have no 2FA.');
    }

    private function chkSession(): array
    {
        $p = array_merge(['timeout_minutes' => 60], $this->policyJson('session.policy'));
        $m = (int) $p['timeout_minutes'];

        return $m > 0 && $m <= 120 ? $this->r('pass', "Idle timeout is {$m} minutes.") : $this->r('warn', $m <= 0 ? 'No idle timeout is set.' : "Idle timeout is {$m} minutes (recommended ≤ 120).");
    }
}
