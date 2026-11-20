<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDomain;
use App\Http\Controllers\Controller;
use App\Services\Developer\DevAccessService;
use App\Services\Developer\DevLogService;
use App\Services\Developer\DevScopes;
use App\Services\Developer\DeveloperAppService;
use App\Services\Developer\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/dev-admin — تحكم كامل في المطورين:
 *  - university: سياسة جامعته + قواعد الأدوار/الإيميلات + المطورين واحد واحد + تطبيقاتهم ومفاتيحهم وwebhooks وسجلهم.
 *  - faculty: قراءة فقط لجامعته.
 *  - admin (المنصة): كل الجامعات (university_id اختياري). من غير university_id بيتعامل مع "سياسة المنصة" (سقف فوق كل الجامعات).
 * كل تعديل بيتسجّل في سجل التكاملات (kind = admin).
 */
class DevAdminController extends Controller
{
    use RunsDomain;

    public function __construct(private DeveloperAppService $apps, private DevLogService $logs, private DevAccessService $access, private WebhookService $hooks)
    {
    }

    /** @return array{role:string,universityId:?int}|null */
    private function scope(Request $r): ?array
    {
        $role = (string) $r->attributes->get('uip_role');
        $uid = $this->uid($r);
        if ($role === 'university') {
            $id = DB::table('universities')->where('user_id', $uid)->value('id');
            return $id ? ['role' => $role, 'universityId' => (int) $id] : null;
        }
        if ($role === 'faculty') {
            $uni = DB::table('faculties')->where('user_id', $uid)->value('university_id');
            return $uni ? ['role' => $role, 'universityId' => (int) $uni] : null;
        }
        if ($role === 'admin') {
            $id = (int) ($r->input('university_id') ?: $r->query('university_id'));
            return ['role' => $role, 'universityId' => $id ?: null];
        }
        return null;
    }

    private function run(Request $r, callable $fn, bool $write = false, int $ok = 200)
    {
        $s = $this->scope($r);
        if (!$s) {
            return $this->apiError('Your account is not linked to a university.', null, 403);
        }
        if ($write && $s['role'] === 'faculty') {
            return $this->apiError('University admins only.', null, 403);
        }
        return $this->dom(fn () => $fn($s), $ok);
    }

    /** مستوى السياسة/القواعد: الجامعة المختارة، أو المنصة (0) للأدمن من غير جامعة. */
    private function level(array $s): int
    {
        return (int) ($s['universityId'] ?? DevAccessService::PLATFORM);
    }

    private function audit(Request $r, string $action, ?int $targetUserId = null, ?string $note = null, ?int $appId = null): void
    {
        $this->logs->record(['user_id' => $targetUserId, 'app_id' => $appId, 'kind' => 'admin', 'method' => 'ADMIN', 'path' => 'dev-admin/' . $action,
            'status' => 200, 'ip' => $r->ip(), 'note' => 'by #' . $this->uid($r) . ($note ? ' · ' . $note : '')]);
    }

    private function target(array $s, int $userId, Request $r, bool $notSelf = false): void
    {
        $this->access->assertManaged($s, $userId);
        if ($notSelf && $userId === $this->uid($r)) {
            throw new \DomainException('You cannot do this to your own account.', 403);
        }
    }

    /* ---------------------------------------------------------------- meta + policy */

    public function meta(Request $r)
    {
        return $this->run($r, fn ($s) => ['scope' => ['role' => $s['role'], 'university_id' => $s['universityId'], 'level' => $s['universityId'] ? 'university' : 'platform'],
            'roles' => $this->access->roleCatalog(), 'scopes' => DevScopes::catalog(), 'events' => config('developer.events'), 'defaults' => config('developer.limits'),
            'summary' => $this->access->summary($s)]);
    }

    public function policy(Request $r)
    {
        return $this->run($r, fn ($s) => ['policy' => $this->access->rawPolicy($this->level($s)), 'platform_policy' => $s['universityId'] ? $this->access->rawPolicy(DevAccessService::PLATFORM) : null,
            'scopes' => DevScopes::catalog(), 'roles' => $this->access->roleCatalog(), 'events' => config('developer.events'), 'defaults' => config('developer.limits'),
            'level' => $s['universityId'] ? 'university' : 'platform']);
    }

    public function savePolicy(Request $r)
    {
        $in = $r->validate([
            'apps_enabled' => 'sometimes|boolean', 'webhooks_enabled' => 'sometimes|boolean', 'require_approval' => 'sometimes|boolean', 'sandbox_only' => 'sometimes|boolean',
            'max_apps_per_user' => 'nullable|integer|min:0|max:100', 'max_keys_per_user' => 'nullable|integer|min:0|max:500', 'max_webhooks_per_user' => 'nullable|integer|min:0|max:100',
            'rate_limit_per_min' => 'nullable|integer|min:1|max:100000', 'default_access' => 'sometimes|string|in:open,closed',
            'allowed_scopes' => 'nullable|array', 'allowed_scopes.*' => 'string|max:40', 'allowed_roles' => 'nullable|array', 'allowed_roles.*' => 'string|max:50',
            'allowed_events' => 'nullable|array', 'allowed_events.*' => 'string|max:60',
        ]);
        return $this->run($r, function ($s) use ($in, $r) {
            $p = $this->access->savePolicy($this->level($s), $in, $this->uid($r));
            $this->audit($r, 'policy.save', null, 'level ' . $this->level($s));
            return ['policy' => $p];
        }, true);
    }

    /** "لو الإيميل ده والدور ده جه، يتسمح له؟" — اختبار القواعد من غير ما نمس حاجة. */
    public function checkAccess(Request $r)
    {
        $in = $r->validate(['email' => 'required|email|max:190', 'role' => 'nullable|string|max:50']);
        return $this->run($r, fn ($s) => ['result' => $this->access->simulate($s['universityId'], $in['email'], $in['role'] ?? null)]);
    }

    /* ---------------------------------------------------------------- rules (إيميل / دومين / دور) */

    public function rules(Request $r)
    {
        return $this->run($r, fn ($s) => ['rules' => $this->access->listRules($this->level($s))]);
    }

    public function addRule(Request $r)
    {
        $in = $r->validate(['kind' => 'required|string|in:email,domain,role', 'pattern' => 'required|string|max:190', 'effect' => 'sometimes|string|in:allow,deny',
            'scopes' => 'nullable|array', 'scopes.*' => 'string|max:40', 'max_apps' => 'nullable|integer|min:0|max:100', 'note' => 'nullable|string|max:200', 'expires_at' => 'nullable|date']);
        return $this->run($r, function ($s) use ($in, $r) {
            $rule = $this->access->addRule($this->level($s), $in, $this->uid($r));
            $this->audit($r, 'rule.add', null, $rule['effect'] . ' ' . $rule['kind'] . ':' . $rule['pattern']);
            return ['rule' => $rule];
        }, true, 201);
    }

    public function updateRule(Request $r, int $id)
    {
        $in = $r->validate(['effect' => 'sometimes|string|in:allow,deny', 'scopes' => 'nullable|array', 'scopes.*' => 'string|max:40', 'max_apps' => 'nullable|integer|min:0|max:100',
            'note' => 'nullable|string|max:200', 'expires_at' => 'nullable|date']);
        return $this->run($r, function ($s) use ($in, $r, $id) {
            $rule = $this->access->updateRule($this->level($s), $id, $in);
            $this->audit($r, 'rule.update', null, '#' . $id);
            return ['rule' => $rule];
        }, true);
    }

    public function deleteRule(Request $r, int $id)
    {
        return $this->run($r, function ($s) use ($r, $id) {
            $this->access->deleteRule($this->level($s), $id);
            $this->audit($r, 'rule.delete', null, '#' . $id);
            return null;
        }, true);
    }

    /* ---------------------------------------------------------------- developers (واحد واحد) */

    public function developers(Request $r)
    {
        return $this->run($r, fn ($s) => $this->access->developers($s, $r->only(['q', 'role', 'status', 'mode']), (int) $r->query('page', 1), (int) $r->query('per_page', 25)));
    }

    public function developer(Request $r, int $userId)
    {
        return $this->run($r, function ($s) use ($userId) {
            $this->access->assertManaged($s, $userId);
            return ['developer' => $this->access->describeUser($userId), 'access' => $this->access->accessPayload($this->access->accessRow($userId)),
                'apps' => $this->apps->listApps($userId), 'keys' => $this->apps->listKeys($userId), 'webhooks' => $this->hooks->list($userId), 'grants' => $this->apps->listGrants($userId),
                'logs' => $this->logs->search(['user_id' => $userId], 1, 10)['items']];
        });
    }

    private function accessRules(): array
    {
        return ['status' => 'sometimes|string|in:allowed,blocked', 'scopes' => 'nullable|array', 'scopes.*' => 'string|max:40', 'max_apps' => 'nullable|integer|min:0|max:100',
            'max_keys' => 'nullable|integer|min:0|max:500', 'max_webhooks' => 'nullable|integer|min:0|max:100', 'webhooks_enabled' => 'nullable|boolean', 'sandbox_only' => 'nullable|boolean',
            'rate_limit_per_min' => 'nullable|integer|min:1|max:100000', 'reason' => 'nullable|string|max:200', 'expires_at' => 'nullable|date', 'resume_apps' => 'sometimes|boolean'];
    }

    /** سماح/حظر + scopes وحدود خاصة بمطوّر واحد (حتى لو معندوش أي نشاط لسه). */
    public function setAccess(Request $r, int $userId)
    {
        $in = $r->validate($this->accessRules());
        return $this->run($r, function ($s) use ($in, $r, $userId) {
            $this->target($s, $userId, $r, ($in['status'] ?? null) === 'blocked');
            $resume = !empty($in['resume_apps']);
            unset($in['resume_apps']);
            $row = $this->access->setAccess($userId, $s['universityId'] ?? $this->access->universityOf($userId), $in, $this->uid($r), $s['role']);
            $resumed = ($resume && $row['status'] === 'allowed') ? $this->access->resumeApps($userId) : 0;
            $this->audit($r, 'access.set', $userId, $row['status'] . ($resumed ? " · resumed {$resumed} apps" : ''));
            return ['access' => $row, 'effective' => $this->access->effective($userId), 'resumed_apps' => $resumed];
        }, true);
    }

    public function clearAccess(Request $r, int $userId)
    {
        return $this->run($r, function ($s) use ($r, $userId) {
            $this->target($s, $userId, $r);
            $this->access->clearAccess($userId);
            $this->audit($r, 'access.clear', $userId);
            return ['effective' => $this->access->effective($userId)];
        }, true);
    }

    /** موافقة / رفض طلب وصول. */
    public function decide(Request $r, int $userId)
    {
        $in = $r->validate(['approve' => 'required|boolean', 'reason' => 'nullable|string|max:200']);
        return $this->run($r, function ($s) use ($in, $r, $userId) {
            $this->target($s, $userId, $r);
            $row = $this->access->decide($userId, $s['universityId'], (bool) $in['approve'], $in['reason'] ?? null, $this->uid($r), $s['role']);
            $this->audit($r, $in['approve'] ? 'access.approve' : 'access.reject', $userId, $in['reason'] ?? null);
            return ['access' => $row, 'effective' => $this->access->effective($userId)];
        }, true);
    }

    /** إيقاف كل مفاتيح/تطبيقات/webhooks مطوّر فورًا (وحظره لو block=true). */
    public function revokeAll(Request $r, int $userId)
    {
        $in = $r->validate(['reason' => 'nullable|string|max:200', 'block' => 'sometimes|boolean']);
        return $this->run($r, function ($s) use ($in, $r, $userId) {
            $this->target($s, $userId, $r, true);
            $out = $this->access->revokeAll($userId, $s['universityId'] ?? $this->access->universityOf($userId), $s['role'], $in['reason'] ?? null, !empty($in['block']), $this->uid($r));
            $this->audit($r, 'developer.revoke_all', $userId, json_encode($out));
            return ['result' => $out, 'effective' => $this->access->effective($userId)];
        }, true);
    }

    /** إجراء جماعي: allow | block | reset على حد أقصى 200 مستخدم. */
    public function bulk(Request $r)
    {
        $in = $r->validate(['user_ids' => 'required|array|min:1|max:200', 'user_ids.*' => 'integer', 'action' => 'required|string|in:allow,block,reset', 'reason' => 'nullable|string|max:200']);
        return $this->run($r, function ($s) use ($in, $r) {
            $done = [];
            $failed = [];
            foreach (array_values(array_unique(array_map('intval', $in['user_ids']))) as $uid) {
                try {
                    $this->target($s, $uid, $r, $in['action'] === 'block');
                    if ($in['action'] === 'reset') {
                        $this->access->clearAccess($uid);
                    } else {
                        $this->access->setAccess($uid, $s['universityId'] ?? $this->access->universityOf($uid),
                            ['status' => $in['action'] === 'allow' ? 'allowed' : 'blocked'] + (isset($in['reason']) ? ['reason' => $in['reason']] : []), $this->uid($r), $s['role']);
                    }
                    $done[] = $uid;
                } catch (\DomainException $e) {
                    $failed[] = ['user_id' => $uid, 'error' => $e->getMessage()];
                }
            }
            $this->audit($r, 'bulk.' . $in['action'], null, count($done) . ' done, ' . count($failed) . ' failed');
            return ['done' => $done, 'failed' => $failed];
        }, true);
    }

    /* ---------------------------------------------------------------- apps */

    public function apps(Request $r)
    {
        return $this->run($r, fn ($s) => $this->apps->adminApps(['university_id' => $s['universityId'], 'status' => $r->query('status'), 'q' => $r->query('q')], (int) $r->query('page', 1), (int) $r->query('per_page', 30)));
    }

    public function app(Request $r, int $id)
    {
        return $this->run($r, fn ($s) => $this->apps->adminAppDetail($id, $s['universityId']));
    }

    public function updateApp(Request $r, int $id)
    {
        $in = $r->validate(['scopes' => 'sometimes|array|min:1', 'scopes.*' => 'string|max:40', 'rate_limit_per_min' => 'nullable|integer|min:1|max:100000']);
        return $this->run($r, function ($s) use ($in, $r, $id) {
            $app = $this->apps->adminUpdateApp($id, $s['universityId'], $in);
            $this->audit($r, 'app.update', $app['owner_user_id'], 'scopes/rate', $id);
            return ['app' => $app];
        }, true);
    }

    public function deleteApp(Request $r, int $id)
    {
        return $this->run($r, function ($s) use ($r, $id) {
            $this->apps->adminDeleteApp($id, $s['universityId']);
            $this->audit($r, 'app.delete', null, null, $id);
            return null;
        }, true);
    }

    public function suspend(Request $r, int $id)
    {
        $in = $r->validate(['reason' => 'nullable|string|max:200']);
        return $this->run($r, function ($s) use ($in, $r, $id) {
            $app = $this->apps->adminSetStatus($id, $s['universityId'], true, $s['role'] === 'admin' ? 'admin' : 'university', $in['reason'] ?? null);
            $this->audit($r, 'app.suspend', $app['owner_user_id'], $in['reason'] ?? null, $id);
            return ['app' => $app];
        }, true);
    }

    public function resume(Request $r, int $id)
    {
        return $this->run($r, function ($s) use ($r, $id) {
            $app = $this->apps->adminSetStatus($id, $s['universityId'], false, $s['role'] === 'admin' ? 'admin' : 'university');
            $this->audit($r, 'app.resume', $app['owner_user_id'], null, $id);
            return ['app' => $app];
        }, true);
    }

    /* ---------------------------------------------------------------- API keys */

    public function keys(Request $r)
    {
        return $this->run($r, fn ($s) => $this->apps->adminKeys(['university_id' => $s['universityId'], 'status' => $r->query('status'), 'mode' => $r->query('mode'), 'q' => $r->query('q'),
            'user_id' => $r->query('user_id'), 'app_id' => $r->query('app_id')], (int) $r->query('page', 1), (int) $r->query('per_page', 30)));
    }

    public function revokeKey(Request $r, int $id)
    {
        return $this->run($r, function ($s) use ($r, $id) {
            $k = $this->apps->adminRevokeKey($id, $s['universityId']);
            $this->audit($r, 'key.revoke', $k['user_id'], $k['prefix']);
            return ['key' => $k];
        }, true);
    }

    /* ---------------------------------------------------------------- webhooks */

    public function webhooks(Request $r)
    {
        return $this->run($r, fn ($s) => $this->hooks->adminList(['university_id' => $s['universityId'], 'q' => $r->query('q'), 'active' => $r->query('active'), 'user_id' => $r->query('user_id')],
            (int) $r->query('page', 1), (int) $r->query('per_page', 30)));
    }

    public function setWebhookActive(Request $r, int $id)
    {
        $in = $r->validate(['active' => 'required|boolean']);
        return $this->run($r, function ($s) use ($in, $r, $id) {
            $h = $this->hooks->adminSetActive($id, $s['universityId'], (bool) $in['active']);
            $this->audit($r, $in['active'] ? 'webhook.enable' : 'webhook.disable', $h['user_id'], '#' . $id);
            return ['webhook' => $h];
        }, true);
    }

    /* ---------------------------------------------------------------- logs */

    public function logs(Request $r)
    {
        return $this->run($r, function ($s) use ($r) {
            $f = $r->only(['app_id', 'kind', 'status', 'from', 'to', 'q', 'user_id']);
            if ($s['universityId']) {
                $f['owner_ids'] = $this->apps->ownerIdsOfUniversity($s['universityId']) ?: [0];
            }
            return $this->logs->search($f, (int) $r->query('page', 1), (int) $r->query('per_page', 30));
        });
    }
}
