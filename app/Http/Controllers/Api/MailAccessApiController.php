<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\MailAccessService;
use App\Services\Mail\MailAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/mail-admin/access — إدارة الوصول للبريد (مين يستخدم، مين يراسل مين، مين يتعمله صندوق، دومينات محظورة/مسموحة، استثناءات مستخدمين).
 *  - admin (المنصة): من غير university_id = قواعد المنصة كلها (بتطبّق على كل الجامعات)، ومعاه = قواعد جامعة بعينها.
 *  - university: قواعد جامعته بس (تقدر تقيّد زيادة، ومتقدرش تفك قيد وضعته المنصة).
 *  - faculty وباقي الأدوار = 403.
 */
class MailAccessApiController extends Controller
{
    public function __construct(private MailAccessService $access, private MailAuditService $audit)
    {
    }

    /** @return int|null 0 = المنصة، رقم = جامعة، null = مش مسموح */
    private function scope(Request $r): ?int
    {
        $role = (string) $r->attributes->get('uip_role');
        $uid = (int) $r->attributes->get('uip_user_id');
        if ($role === 'university') {
            $id = DB::table('universities')->where('user_id', $uid)->value('id');
            return $id ? (int) $id : null;
        }
        if ($role === 'admin') {
            $id = (int) ($r->input('university_id') ?: $r->query('university_id'));
            if (!$id) {
                return 0;
            }
            return DB::table('universities')->where('id', $id)->exists() ? $id : null;
        }
        return null;
    }

    private function log(Request $r, int $scope, string $action, string $summary, array $meta = []): void
    {
        $tenantId = $scope ? DB::table('mail_tenants')->where('university_id', $scope)->value('id') : null;
        $this->audit->record($action, $summary, ['university_id' => $scope ?: null, 'tenant_id' => $tenantId ? (int) $tenantId : null, 'actor_user_id' => (int) $r->attributes->get('uip_user_id'),
            'actor_role' => (string) $r->attributes->get('uip_role'), 'ip' => (string) $r->ip(), 'target_type' => 'access', 'meta' => $meta + ['scope' => $scope ? 'university' : 'platform']]);
    }

    /** $audit = [action, summary(callable|string), meta?] بعد نجاح العملية. */
    private function run(Request $r, callable $fn, int $ok = 200, ?array $audit = null)
    {
        $scope = $this->scope($r);
        if ($scope === null) {
            return $this->apiError('Mail access control is for platform and university admins only.', null, 403);
        }
        try {
            $res = $fn($scope);
            if ($audit) {
                $summary = is_callable($audit[1]) ? $audit[1]($res) : $audit[1];
                $meta = isset($audit[2]) ? (is_callable($audit[2]) ? $audit[2]($res) : $audit[2]) : [];
                $this->log($r, $scope, $audit[0], (string) $summary, $meta);
            }
            return $this->apiSuccess($res, 'OK', $ok);
        } catch (\DomainException $e) {
            return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }
    }

    public function overview(Request $r)
    {
        return $this->run($r, fn ($s) => $this->access->overview($s));
    }

    public function saveSettings(Request $r)
    {
        $in = $r->validate(['external_domain_mode' => 'sometimes|in:open,allowlist', 'personal_mailbox_creation' => 'sometimes|in:auto,admin_only']);
        return $this->run($r, fn ($s) => $this->access->saveSettings($s, $in, (int) $r->attributes->get('uip_user_id')), 200,
            ['mail.access.settings_updated', 'Access settings updated: ' . implode(', ', array_keys($in)), ['fields' => $in]]);
    }

    public function saveRole(Request $r, string $slug)
    {
        $in = $r->validate(['use' => 'sometimes|boolean', 'send' => 'sometimes|boolean', 'receive' => 'sometimes|boolean', 'send_external' => 'sometimes|boolean', 'create' => 'sometimes|boolean']);
        return $this->run($r, fn ($s) => $this->access->saveRolePolicy($s, $slug, $in, (int) $r->attributes->get('uip_user_id')), 200,
            ['mail.access.role_policy', "Role policy updated: {$slug}", ['role' => $slug, 'policy' => $in]]);
    }

    public function matrix(Request $r)
    {
        return $this->run($r, fn ($s) => $this->access->matrix($s));
    }

    public function saveMatrix(Request $r)
    {
        $in = $r->validate(['blocked' => 'present|array|max:400', 'blocked.*.from' => 'required|string|max:50', 'blocked.*.to' => 'required|string|max:50']);
        return $this->run($r, fn ($s) => $this->access->saveMatrix($s, $in['blocked'], (int) $r->attributes->get('uip_user_id')), 200,
            ['mail.access.matrix_updated', fn ($res) => 'Role matrix updated: ' . count($res['blocked']) . ' blocked pair(s)', fn ($res) => ['blocked' => count($res['blocked'])]]);
    }

    public function domains(Request $r)
    {
        return $this->run($r, fn ($s) => ['rules' => $this->access->domainRules($s), 'settings' => $this->access->settingsFor($s)]);
    }

    public function addDomain(Request $r)
    {
        $in = $r->validate(['domain' => 'required|string|max:140', 'type' => 'required|in:block,allow', 'direction' => 'sometimes|in:send,receive,both', 'note' => 'sometimes|nullable|string|max:190']);
        return $this->run($r, fn ($s) => $this->access->addDomainRule($s, $in['domain'], $in['type'], $in['direction'] ?? 'both', $in['note'] ?? null, (int) $r->attributes->get('uip_user_id')), 201,
            ['mail.access.domain_added', fn ($res) => "Domain rule added: {$res['type']} {$res['domain']} ({$res['direction']})", fn ($res) => $res]);
    }

    public function removeDomain(Request $r, int $id)
    {
        return $this->run($r, fn ($s) => $this->access->removeDomainRule($s, $id), 200,
            ['mail.access.domain_removed', fn ($res) => "Domain rule removed: {$res['type']} {$res['domain']}", fn ($res) => $res]);
    }

    public function users(Request $r)
    {
        return $this->run($r, fn ($s) => $this->access->overrides($s, (string) $r->query('q', ''), (int) $r->query('page', 1), (int) $r->query('per_page', 25)));
    }

    public function saveUser(Request $r)
    {
        $in = $r->validate(['user' => 'required|string|max:190', 'reason' => 'sometimes|nullable|string|max:190',
            'perms' => 'required|array', 'perms.use' => 'nullable|boolean', 'perms.send' => 'nullable|boolean', 'perms.receive' => 'nullable|boolean',
            'perms.send_external' => 'nullable|boolean', 'perms.create' => 'nullable|boolean']);
        return $this->run($r, fn ($s) => $this->access->saveOverride($s, $in['user'], $in['perms'], $in['reason'] ?? null, (int) $r->attributes->get('uip_user_id')), 200,
            ['mail.access.user_override', fn ($res) => ($res['removed'] ? 'User override removed: ' : 'User override saved: ') . $res['email'], fn ($res) => ['user_id' => $res['id'], 'removed' => $res['removed']]]);
    }

    public function removeUser(Request $r, int $id)
    {
        return $this->run($r, fn ($s) => $this->access->removeOverride($s, $id), 200,
            ['mail.access.user_override_removed', fn ($res) => 'User override removed: ' . $res['email'], fn ($res) => ['user_id' => $res['user_id']]]);
    }

    public function check(Request $r)
    {
        $in = $r->validate(['sender' => 'required|string|max:190', 'recipient' => 'required|string|max:190']);
        return $this->run($r, fn ($s) => $this->access->check($s, $in['sender'], $in['recipient']));
    }
}
