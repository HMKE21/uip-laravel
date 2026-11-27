<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use App\Repositories\BlockedIpRepository;
use App\Services\AuditLogService;
use App\Services\CountryRestrictionPolicyService;
use App\Services\IpRestrictionPolicyService;
use App\Services\NetworkControlService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * /api/v1/security/network/* — Network Control (بند 4):
 *  - الحظر: يدوي (IP أو CIDR) بمدة/دائم، استيراد جماعي (مع معاينة)، تصدير CSV، فك.
 *  - قائمة الموثوقين (Allowlist "لا تحظر أبدًا") بمدة اختيارية.
 *  - قواعد حد المعدل لكل مسار (429 أو حظر الـ IP لمدة).
 *  - ملخّص سياسة الدولة/IP (التعديل بيتم من Security Policies زي ما هو).
 * القراءة: security_admin / security_officer / admin. الكتابة: security_admin / admin (الـ route: write:security.policies.manage).
 */
class SecurityNetworkApiController extends Controller
{
    private const MAX_MINUTES = 525600; // سنة
    private const MAX_RULES = 100;
    private const METHODS = ['ANY', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private BlockedIpRepository $blocked,
        private NetworkControlService $network,
        private IpRestrictionPolicyService $ipPolicy,
        private CountryRestrictionPolicyService $countryPolicy,
        private AuditLogService $auditLog
    ) {
    }

    // ---- overview --------------------------------------------------------------------------------

    /** GET /overview */
    public function overview(Request $request)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can view network control.', null, 403);
        }
        if (!$this->tablesReady()) {
            return $this->apiError('Network control tables are missing. Run the database migrations.', null, 503);
        }

        $blocks = array_map(fn ($b) => $this->presentBlock($b), $this->blocked->all());
        $trusted = DB::table('network_trusted_ips as t')
            ->leftJoin('users as u', 'u.id', '=', 't.added_by')
            ->where(fn ($q) => $q->whereNull('t.expires_at')->orWhere('t.expires_at', '>', now()))
            ->select('t.*', 'u.full_name as added_by_name')
            ->orderByDesc('t.created_at')->get()->map(fn ($r) => (array) $r)->all();
        $rules = DB::table('network_rate_rules')->orderByDesc('enabled')->orderBy('id')->get()
            ->map(fn ($r) => $this->presentRule((array) $r))->all();

        $country = $this->countryPolicy->getPolicy();
        $ipPol = $this->ipPolicy->getPolicy();

        $soon = now()->addDay();
        $kpis = [
            'active_blocks'   => count($blocks),
            'cidr_blocks'     => count(array_filter($blocks, fn ($b) => $b['is_cidr'])),
            'expiring_24h'    => count(array_filter($blocks, fn ($b) => $b['expires_at'] && strtotime((string) $b['expires_at']) <= $soon->getTimestamp())),
            'trusted'         => count($trusted),
            'rules_enabled'   => count(array_filter($rules, fn ($r) => $r['enabled'])),
            'violations_today' => array_sum(array_map(fn ($r) => $r['hits_today'], $rules)),
        ];

        return $this->apiSuccess([
            'blocks'  => $blocks,
            'trusted' => $trusted,
            'rules'   => $rules,
            'country' => [
                'mode'              => $country['mode'],
                'allowed_countries' => $country['allowed_countries'],
                'blocked_countries' => $country['blocked_countries'],
                'fail_open'         => $country['fail_open'],
            ],
            'ip_policy' => [
                'mode'           => $ipPol['mode'],
                'allowlist_size' => count($ipPol['allowlist']),
                'denylist_size'  => count($ipPol['denylist']),
            ],
        ], 'Network control retrieved successfully.', 200, [
            'kpis'      => $kpis,
            'can_edit'  => $this->canEdit($request),
            'caller_ip' => (string) $request->ip(),
            'max_import' => NetworkControlService::MAX_IMPORT,
        ]);
    }

    // ---- blocks ----------------------------------------------------------------------------------

    /** POST /blocks  { ip, reason?, duration_minutes? (0/null = دائم) } */
    public function block(Request $request)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only Security Administrators can block addresses.', null, 403);
        }
        $entry = trim((string) $request->input('ip', ''));
        if (!NetworkControlService::validEntry($entry)) {
            return $this->apiError('Enter a valid IP address or CIDR range (e.g. 203.0.113.0/24).', ['ip' => 'invalid'], 422);
        }
        $entry = NetworkControlService::normalizeEntry($entry);
        $minutes = $this->minutes($request->input('duration_minutes'));
        if ($minutes === false) {
            return $this->apiError('Duration must be between 1 minute and 1 year (or empty for permanent).', ['duration_minutes' => 'invalid'], 422);
        }
        if (NetworkControlService::matches((string) $request->ip(), $entry)) {
            return $this->apiError('This would block your own IP address (' . $request->ip() . ').', ['ip' => 'self'], 422);
        }

        $reason = trim((string) $request->input('reason', '')) ?: null;
        $res = $this->blocked->blockEntry($entry, $reason, $this->userId($request), $minutes ? now()->addMinutes($minutes) : null, 'manual');
        if ($res === 'trusted') {
            return $this->apiError('This address is on the trusted list — remove it from there first.', ['ip' => 'trusted'], 422);
        }
        if ($res === 'exists') {
            return $this->apiError('This address is already blocked.', ['ip' => 'exists'], 422);
        }
        $this->audit($request, 'network.block_added', 'blocked_ip', null, ['ip' => $entry, 'minutes' => $minutes, 'reason' => $reason]);

        return $this->apiSuccess(null, 'Address blocked.', 201);
    }

    /**
     * POST /blocks/import  { text, reason?, duration_minutes?, dry_run? }
     * سطر لكل IP/CIDR (أو CSV: ip,reason). dry_run=true => معاينة بس من غير كتابة.
     */
    public function importBlocks(Request $request)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only Security Administrators can import blocks.', null, 403);
        }
        $parsed = NetworkControlService::parseEntries((string) $request->input('text', ''));
        $valid = $parsed['valid'];
        if (!$valid && !$parsed['invalid']) {
            return $this->apiError('Paste at least one IP address or CIDR range.', ['text' => 'empty'], 422);
        }
        if (count($valid) > NetworkControlService::MAX_IMPORT) {
            return $this->apiError('Too many entries in one import (max ' . NetworkControlService::MAX_IMPORT . ').', ['text' => 'too_many'], 422);
        }
        $minutes = $this->minutes($request->input('duration_minutes'));
        if ($minutes === false) {
            return $this->apiError('Duration must be between 1 minute and 1 year (or empty for permanent).', ['duration_minutes' => 'invalid'], 422);
        }
        $defaultReason = trim((string) $request->input('reason', '')) ?: 'Bulk import';
        $dry = filter_var($request->input('dry_run', false), FILTER_VALIDATE_BOOLEAN);
        $callerIp = (string) $request->ip();
        $expires = $minutes ? now()->addMinutes($minutes) : null;

        $existing = $this->existingActive(array_column($valid, 'ip'));
        $stats = ['added' => 0, 'already_blocked' => 0, 'skipped_trusted' => 0, 'skipped_self' => 0, 'invalid' => count($parsed['invalid']), 'duplicates' => $parsed['duplicates']];

        foreach ($valid as $row) {
            $entry = $row['ip'];
            if (NetworkControlService::matches($callerIp, $entry)) {
                $stats['skipped_self']++;
                continue;
            }
            if (!NetworkControlService::isCidr($entry) && $this->network->isTrusted($entry)) {
                $stats['skipped_trusted']++;
                continue;
            }
            if (isset($existing[$entry])) {
                $stats['already_blocked']++;
                continue;
            }
            if ($dry) {
                $stats['added']++;
                continue;
            }
            $res = $this->blocked->blockEntry($entry, $row['reason'] ?? $defaultReason, $this->userId($request), $expires, 'import');
            if ($res === 'added') {
                $stats['added']++;
            } elseif ($res === 'trusted') {
                $stats['skipped_trusted']++;
            } else {
                $stats['already_blocked']++;
            }
        }

        if (!$dry && $stats['added'] > 0) {
            $this->audit($request, 'network.block_imported', 'blocked_ip', null, ['added' => $stats['added'], 'minutes' => $minutes]);
        }

        return $this->apiSuccess(['dry_run' => $dry, 'stats' => $stats, 'invalid' => $parsed['invalid']], $dry ? 'Import preview.' : 'Import finished.');
    }

    /** DELETE /blocks/{id} */
    public function unblock(Request $request, int $id)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only Security Administrators can unblock addresses.', null, 403);
        }
        $row = BlockedIp::find($id);
        if (!$row) {
            return $this->apiError('Block not found.', null, 404);
        }
        $ip = (string) $row->ip_address;
        $this->blocked->unblock($id);
        $this->audit($request, 'network.block_removed', 'blocked_ip', $id, ['ip' => $ip]);

        return $this->apiSuccess(null, 'Address unblocked.');
    }

    /** GET /blocks/export — CSV بالحظر النشط. */
    public function exportBlocks(Request $request)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can export blocks.', null, 403);
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['ip', 'reason', 'source', 'blocked_by', 'created_at', 'expires_at']);
        foreach ($this->blocked->all() as $b) {
            fputcsv($stream, [$b['ip_address'] ?? '', $b['reason'] ?? '', $b['source'] ?? 'manual', $b['blocked_by_name'] ?? '', $b['created_at'] ?? '', $b['expires_at'] ?? '']);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="blocked-ips-' . date('Y-m-d_His') . '.csv"',
        ]);
    }

    // ---- trusted ---------------------------------------------------------------------------------

    /** POST /trusted  { ip, label?, duration_minutes? } */
    public function addTrusted(Request $request)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only Security Administrators can edit the trusted list.', null, 403);
        }
        $entry = trim((string) $request->input('ip', ''));
        if (!NetworkControlService::validEntry($entry)) {
            return $this->apiError('Enter a valid IP address or CIDR range.', ['ip' => 'invalid'], 422);
        }
        $entry = NetworkControlService::normalizeEntry($entry);
        $minutes = $this->minutes($request->input('duration_minutes'));
        if ($minutes === false) {
            return $this->apiError('Duration must be between 1 minute and 1 year (or empty for permanent).', ['duration_minutes' => 'invalid'], 422);
        }
        if (DB::table('network_trusted_ips')->count() >= 1000) {
            return $this->apiError('The trusted list is full (1000 entries).', null, 422);
        }
        $row = DB::table('network_trusted_ips')->where('ip_address', $entry)->first();
        if ($row) {
            $stillActive = $row->expires_at === null || strtotime((string) $row->expires_at) > time();
            if ($stillActive) {
                return $this->apiError('This address is already trusted.', ['ip' => 'exists'], 422);
            }
            DB::table('network_trusted_ips')->where('id', $row->id)->delete();
        }
        $id = DB::table('network_trusted_ips')->insertGetId([
            'ip_address' => $entry,
            'label'      => mb_substr(trim((string) $request->input('label', '')), 0, 160) ?: null,
            'added_by'   => $this->userId($request),
            'expires_at' => $minutes ? now()->addMinutes($minutes) : null,
            'created_at' => now(),
        ]);
        NetworkControlService::forgetCaches();
        $this->audit($request, 'network.trusted_added', 'network_trusted_ip', $id, ['ip' => $entry, 'minutes' => $minutes]);

        return $this->apiSuccess(null, 'Address added to the trusted list.', 201);
    }

    /** DELETE /trusted/{id} */
    public function removeTrusted(Request $request, int $id)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only Security Administrators can edit the trusted list.', null, 403);
        }
        $row = DB::table('network_trusted_ips')->find($id);
        if (!$row) {
            return $this->apiError('Entry not found.', null, 404);
        }
        DB::table('network_trusted_ips')->where('id', $id)->delete();
        NetworkControlService::forgetCaches();
        $this->audit($request, 'network.trusted_removed', 'network_trusted_ip', $id, ['ip' => $row->ip_address]);

        return $this->apiSuccess(null, 'Removed from the trusted list.');
    }

    // ---- rate rules ------------------------------------------------------------------------------

    /** POST /rate-rules */
    public function storeRule(Request $request)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only Security Administrators can edit rate limits.', null, 403);
        }
        if (DB::table('network_rate_rules')->count() >= self::MAX_RULES) {
            return $this->apiError('Too many rules (max ' . self::MAX_RULES . ').', null, 422);
        }
        $data = $this->validateRule($request, null);
        if (is_string($data)) {
            return $this->apiError($data, null, 422);
        }
        $data['created_by'] = $this->userId($request);
        $data['created_at'] = now();
        $data['updated_at'] = now();
        $id = DB::table('network_rate_rules')->insertGetId($data);
        NetworkControlService::forgetCaches();
        $this->audit($request, 'network.rate_rule_created', 'network_rate_rule', $id, ['name' => $data['name'], 'path' => $data['path_pattern'], 'limit' => $data['limit_per_min']]);

        return $this->apiSuccess(null, 'Rate limit rule created.', 201);
    }

    /** PATCH /rate-rules/{id} */
    public function updateRule(Request $request, int $id)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only Security Administrators can edit rate limits.', null, 403);
        }
        $row = DB::table('network_rate_rules')->find($id);
        if (!$row) {
            return $this->apiError('Rule not found.', null, 404);
        }
        $data = $this->validateRule($request, (array) $row);
        if (is_string($data)) {
            return $this->apiError($data, null, 422);
        }
        $data['updated_at'] = now();
        DB::table('network_rate_rules')->where('id', $id)->update($data);
        NetworkControlService::forgetCaches();
        $this->audit($request, 'network.rate_rule_updated', 'network_rate_rule', $id, ['name' => $data['name'], 'enabled' => (bool) $data['enabled']]);

        return $this->apiSuccess(null, 'Rate limit rule updated.');
    }

    /** DELETE /rate-rules/{id} */
    public function destroyRule(Request $request, int $id)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only Security Administrators can edit rate limits.', null, 403);
        }
        $row = DB::table('network_rate_rules')->find($id);
        if (!$row) {
            return $this->apiError('Rule not found.', null, 404);
        }
        DB::table('network_rate_rules')->where('id', $id)->delete();
        NetworkControlService::forgetCaches();
        $this->audit($request, 'network.rate_rule_deleted', 'network_rate_rule', $id, ['name' => $row->name]);

        return $this->apiSuccess(null, 'Rate limit rule deleted.');
    }

    // ---- helpers ---------------------------------------------------------------------------------

    /** @return array<string,mixed>|string القيم الجاهزة للحفظ، أو رسالة خطأ. */
    private function validateRule(Request $request, ?array $current)
    {
        $in = fn (string $k, $default = null) => $request->has($k) ? $request->input($k) : ($current[$k] ?? $default);

        $name = trim((string) $in('name', ''));
        if ($name === '' || mb_strlen($name) > 120) {
            return 'Give the rule a name (up to 120 characters).';
        }
        $pattern = ltrim(trim((string) $in('path_pattern', '')), '/');
        if (!preg_match('#^api/[A-Za-z0-9_\-./*{}]*$#', $pattern) || strlen($pattern) > 190) {
            return 'The path must start with api/ (e.g. api/v1/auth/*) and use only letters, digits, - _ . / and *.';
        }
        // الصفحة نفسها لازم تفضل شغّالة: نمنع أي نمط يلمس مسارات إدارة الشبكة.
        if (Str::is($pattern, 'api/v1/security/network') || Str::is($pattern, 'api/v1/security/network/overview')) {
            return 'This path would also limit the Network control page itself. Use a more specific pattern.';
        }
        $limit = (int) $in('limit_per_min', 0);
        if ($limit < 1 || $limit > 100000) {
            return 'Requests per minute must be between 1 and 100000.';
        }
        $method = strtoupper((string) $in('method', 'ANY'));
        if (!in_array($method, self::METHODS, true)) {
            return 'Invalid HTTP method.';
        }
        $scope = (string) $in('scope', 'ip');
        if (!in_array($scope, ['ip', 'user'], true)) {
            return 'Scope must be ip or user.';
        }
        $action = (string) $in('action', 'throttle');
        if (!in_array($action, ['throttle', 'block'], true)) {
            return 'Action must be throttle or block.';
        }
        $blockMinutes = null;
        if ($action === 'block') {
            if ($scope !== 'ip') {
                return 'Blocking needs the per-IP scope (a shared address would otherwise be blocked for one user).';
            }
            $bm = (int) $in('block_minutes', 0);
            if ($bm < 0 || $bm > self::MAX_MINUTES) {
                return 'Block duration must be between 0 (permanent) and 1 year in minutes.';
            }
            $blockMinutes = $bm > 0 ? $bm : null;
        }

        return [
            'name'          => $name,
            'path_pattern'  => $pattern,
            'method'        => $method,
            'limit_per_min' => $limit,
            'scope'         => $scope,
            'action'        => $action,
            'block_minutes' => $blockMinutes,
            'enabled'       => filter_var($in('enabled', true), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];
    }

    /** @return int|null|false null = دائم، false = قيمة غلط */
    private function minutes($v)
    {
        if ($v === null || $v === '' || (string) $v === '0') {
            return null;
        }
        if (!is_numeric($v) || (int) $v < 1 || (int) $v > self::MAX_MINUTES) {
            return false;
        }
        return (int) $v;
    }

    /** @param string[] $entries @return array<string,true> */
    private function existingActive(array $entries): array
    {
        $out = [];
        foreach (array_chunk($entries, 500) as $chunk) {
            $q = BlockedIp::whereIn('ip_address', $chunk)
                ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
            foreach ($q->pluck('ip_address') as $ip) {
                $out[(string) $ip] = true;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $b */
    private function presentBlock(array $b): array
    {
        $ip = (string) ($b['ip_address'] ?? '');
        $exp = $b['expires_at'] ?? null;
        return [
            'id'              => (int) $b['id'],
            'ip_address'      => $ip,
            'is_cidr'         => NetworkControlService::isCidr($ip),
            'reason'          => $b['reason'] ?? null,
            'source'          => $b['source'] ?? 'manual',
            'blocked_by_name' => $b['blocked_by_name'] ?? null,
            'created_at'      => $b['created_at'] ?? null,
            'expires_at'      => $exp,
        ];
    }

    /** @param array<string,mixed> $r */
    private function presentRule(array $r): array
    {
        $hits = (int) Cache::get('netrulehits:' . TenantContext::ns() . $r['id'] . ':' . date('Ymd'), 0);
        return [
            'id'            => (int) $r['id'],
            'name'          => $r['name'],
            'path_pattern'  => $r['path_pattern'],
            'method'        => $r['method'],
            'limit_per_min' => (int) $r['limit_per_min'],
            'scope'         => $r['scope'],
            'action'        => $r['action'],
            'block_minutes' => $r['block_minutes'] !== null ? (int) $r['block_minutes'] : null,
            'enabled'       => (bool) $r['enabled'],
            'hits_today'    => $hits,
        ];
    }

    private function tablesReady(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable('network_trusted_ips') && \Illuminate\Support\Facades\Schema::hasTable('network_rate_rules');
        } catch (\Throwable) {
            return false;
        }
    }

    private function audit(Request $request, string $action, string $type, $id, array $values): void
    {
        $this->auditLog->record($this->userId($request), $action, $type, $id, null, $values, $request->ip());
    }

    private function userId(Request $request): ?int
    {
        $id = $request->attributes->get('uip_user_id');
        return $id !== null ? (int) $id : null;
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
