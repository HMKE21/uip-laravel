<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDomain;
use App\Http\Controllers\Controller;
use App\Services\Developer\DevLogService;
use App\Services\Developer\DevScopes;
use App\Services\Developer\DevUsageService;
use App\Services\Developer\DeveloperAppService;
use App\Services\Developer\OAuthException;
use App\Services\Developer\OAuthService;
use App\Services\Developer\SandboxMailService;
use App\Services\Developer\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/developer — Developer Center (المرحلة 6) لأي مستخدم: Apps, API Keys, Grants, Webhooks, Sandbox, السجل، وموافقة OAuth.
 * كله بيتحدد بالمستخدم الحالي (JWT) — مفيش وصول لحاجة تخص مستخدم تاني.
 */
class DeveloperCenterController extends Controller
{
    use RunsDomain;

    public function __construct(private DeveloperAppService $apps, private WebhookService $hooks, private SandboxMailService $sandbox,
                                private DevLogService $logs, private OAuthService $oauth, private \App\Services\Developer\DevAccessService $access)
    {
    }

    /** وصولي كمطوّر: مسموح؟ ولو لأ ليه، وأقدر أطلب وصول؟ + scopes وحدودي الفعلية. */
    public function access(Request $r)
    {
        return $this->dom(fn () => ['access' => $this->access->effective($this->uid($r))]);
    }

    public function requestAccess(Request $r)
    {
        $in = $r->validate(['note' => 'nullable|string|max:300']);
        return $this->dom(fn () => ['access' => $this->access->requestAccess($this->uid($r), $in['note'] ?? null)]);
    }

    /** الحدود الفعلية للمستخدم (بعد المنصة + الجامعة + استثناءه) بنفس مفاتيح config('developer.limits') عشان الواجهة القديمة تفضل شغالة. */
    private function effectiveLimits(int $u): array
    {
        $l = (array) config('developer.limits');
        $p = $this->apps->policyFor($u);
        $l['apps_per_user'] = $p['max_apps_per_user'];
        $l['keys_per_user'] = $p['max_keys_per_user'];
        $l['webhooks_per_user'] = $p['max_webhooks_per_user'];
        if (!empty($p['rate_limit_per_min'])) {
            $l['rate_per_min'] = min((int) $l['rate_per_min'], (int) $p['rate_limit_per_min']);
        }
        return $l;
    }

    public function overview(Request $r)
    {
        return $this->dom(function () use ($r) {
            $u = $this->uid($r);
            return [
                'enabled' => (bool) config('developer.enabled'), 'api_version' => config('developer.api_version'),
                'policy' => $this->apps->policyFor($u), 'limits' => $this->effectiveLimits($u), 'access' => $this->access->effective($u),
                'scopes' => DevScopes::catalog(), 'events' => config('developer.events'),
                'sandbox_address' => $this->sandbox->address($u),
                'counts' => [
                    'apps' => DB::table('dev_apps')->where('owner_user_id', $u)->count(),
                    'keys' => DB::table('dev_api_keys')->where('user_id', $u)->whereNull('revoked_at')->count(),
                    'webhooks' => DB::table('dev_webhooks')->where('user_id', $u)->count(),
                    'grants' => DB::table('dev_oauth_grants')->where('user_id', $u)->whereNull('revoked_at')->count(),
                ],
            ];
        });
    }

    /* ---------------------------------------------------------------- apps */

    private function appRules(bool $create): array
    {
        return [
            'name' => ($create ? 'required' : 'sometimes') . '|string|max:80', 'description' => 'nullable|string|max:500', 'homepage_url' => 'nullable|string|max:300',
            'client_type' => 'sometimes|string|in:confidential,public', 'redirect_uris' => 'nullable|array', 'redirect_uris.*' => 'string|max:400',
            'scopes' => 'nullable|array', 'scopes.*' => 'string|max:40',
        ];
    }

    public function apps(Request $r)
    {
        return $this->dom(fn () => ['apps' => $this->apps->listApps($this->uid($r))]);
    }

    /** المطوّر يبعت التطبيق لمراجعة الجامعة/المنصة. */
    public function submitReview(Request $r, int $id)
    {
        $in = $r->validate(['message' => 'required|string|min:10|max:500']);
        return $this->dom(fn () => ['app' => $this->apps->submitForReview($this->uid($r), $id, $in['message'])]);
    }

    public function withdrawReview(Request $r, int $id)
    {
        return $this->dom(fn () => ['app' => $this->apps->withdrawReview($this->uid($r), $id)]);
    }

    public function reviewStatus(Request $r, int $id)
    {
        return $this->dom(fn () => $this->apps->ownerReview($this->uid($r), $id));
    }

    public function createApp(Request $r)
    {
        $in = $r->validate($this->appRules(true));
        return $this->dom(fn () => $this->apps->createApp($this->uid($r), $in), 201);
    }

    public function app(Request $r, int $id)
    {
        return $this->dom(fn () => ['app' => $this->apps->getApp($this->uid($r), $id)]);
    }

    public function updateApp(Request $r, int $id)
    {
        $in = $r->validate($this->appRules(false));
        return $this->dom(fn () => $this->apps->updateApp($this->uid($r), $id, $in));
    }

    public function rotateAppSecret(Request $r, int $id)
    {
        return $this->dom(fn () => $this->apps->rotateSecret($this->uid($r), $id));
    }

    public function setAppStatus(Request $r, int $id)
    {
        $in = $r->validate(['active' => 'required|boolean']);
        return $this->dom(fn () => $this->apps->setOwnerStatus($this->uid($r), $id, (bool) $in['active']));
    }

    public function deleteApp(Request $r, int $id)
    {
        return $this->dom(function () use ($r, $id) { $this->apps->deleteApp($this->uid($r), $id); return null; });
    }

    /* ---------------------------------------------------------------- keys */

    public function keys(Request $r)
    {
        return $this->dom(fn () => ['keys' => $this->apps->listKeys($this->uid($r))]);
    }

    public function createKey(Request $r)
    {
        $in = $r->validate(['name' => 'required|string|max:80', 'mode' => 'sometimes|string|in:live,sandbox', 'scopes' => 'nullable|array', 'scopes.*' => 'string|max:40',
            'app_id' => 'nullable|integer', 'mail_account_id' => 'nullable|integer', 'expires_in_days' => 'nullable|integer|min:1|max:3650',
            'ip_allowlist' => 'nullable|array', 'ip_allowlist.*' => 'string|max:64']);
        return $this->dom(fn () => $this->apps->createKey($this->uid($r), $in), 201);
    }

    public function updateKey(Request $r, int $id)
    {
        $in = $r->validate(['name' => 'sometimes|string|max:80', 'scopes' => 'nullable|array', 'scopes.*' => 'string|max:40', 'ip_allowlist' => 'nullable|array', 'ip_allowlist.*' => 'string|max:64']);
        return $this->dom(fn () => $this->apps->updateKey($this->uid($r), $id, $in));
    }

    public function revokeKey(Request $r, int $id)
    {
        return $this->dom(function () use ($r, $id) { $this->apps->revokeKey($this->uid($r), $id); return null; });
    }

    /* ---------------------------------------------------------------- grants (التطبيقات اللي وافقت لها) */

    public function grants(Request $r)
    {
        return $this->dom(fn () => ['grants' => $this->apps->listGrants($this->uid($r))]);
    }

    public function revokeGrant(Request $r, int $id)
    {
        return $this->dom(function () use ($r, $id) { $this->apps->revokeGrant($this->uid($r), $id); return null; });
    }

    /* ---------------------------------------------------------------- OAuth consent (شاشة الموافقة داخل المنصة) */

    private function oauthQuery(Request $r): array
    {
        return $r->only(['client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'code_challenge', 'code_challenge_method']);
    }

    private function oauth(callable $fn)
    {
        try {
            return $this->apiSuccess($fn(), 'OK');
        } catch (OAuthException $e) {
            return $this->apiError($e->getMessage(), ['error' => $e->oauthError, 'redirect_to' => $e->redirectTo], $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 400);
        }
    }

    public function authorizeInfo(Request $r)
    {
        return $this->oauth(fn () => $this->oauth->authorizeInfo($this->uid($r), $this->oauthQuery($r)));
    }

    public function authorizeDecide(Request $r)
    {
        $r->validate(['allow' => 'required|boolean', 'mail_account_id' => 'nullable|integer']);
        return $this->oauth(fn () => $this->oauth->decide($this->uid($r), $this->oauthQuery($r), $r->boolean('allow'), $r->input('mail_account_id') ? (int) $r->input('mail_account_id') : null));
    }

    /* ---------------------------------------------------------------- webhooks */

    private function hookRules(bool $create): array
    {
        return ['name' => 'nullable|string|max:80', 'url' => ($create ? 'required' : 'sometimes') . '|string|max:500', 'events' => ($create ? 'required' : 'sometimes') . '|array|min:1', 'events.*' => 'string|max:60',
            'mode' => 'sometimes|string|in:live,sandbox', 'app_id' => 'nullable|integer', 'mail_account_id' => 'nullable|integer'];
    }

    public function webhooks(Request $r)
    {
        return $this->dom(fn () => ['webhooks' => $this->hooks->list($this->uid($r))]);
    }

    public function createWebhook(Request $r)
    {
        $in = $r->validate($this->hookRules(true));
        return $this->dom(fn () => $this->hooks->create($this->uid($r), $in), 201);
    }

    public function updateWebhook(Request $r, int $id)
    {
        $in = $r->validate($this->hookRules(false));
        return $this->dom(fn () => $this->hooks->update($this->uid($r), $id, $in));
    }

    public function setWebhookActive(Request $r, int $id)
    {
        $in = $r->validate(['active' => 'required|boolean']);
        return $this->dom(fn () => $this->hooks->setActive($this->uid($r), $id, (bool) $in['active']));
    }

    public function rotateWebhookSecret(Request $r, int $id)
    {
        return $this->dom(fn () => $this->hooks->rotateSecret($this->uid($r), $id));
    }

    public function deleteWebhook(Request $r, int $id)
    {
        return $this->dom(function () use ($r, $id) { $this->hooks->delete($this->uid($r), $id); return null; });
    }

    public function webhookDeliveries(Request $r, int $id)
    {
        return $this->dom(fn () => $this->hooks->deliveries($this->uid($r), $id, (int) $r->query('page', 1), (int) $r->query('per_page', 25)));
    }

    public function delivery(Request $r, int $id)
    {
        return $this->dom(fn () => $this->hooks->delivery($this->uid($r), $id));
    }

    public function testWebhook(Request $r, int $id)
    {
        return $this->dom(fn () => $this->hooks->test($this->uid($r), $id));
    }

    public function redeliver(Request $r, int $id)
    {
        return $this->dom(fn () => $this->hooks->redeliver($this->uid($r), $id));
    }

    /* ---------------------------------------------------------------- sandbox */

    public function sandboxMe(Request $r)
    {
        return $this->dom(fn () => $this->sandbox->me($this->uid($r)));
    }

    public function sandboxList(Request $r)
    {
        return $this->dom(fn () => $this->sandbox->list($this->uid($r), $r->only(['folder', 'q', 'starred', 'unread', 'page', 'per_page'])));
    }

    public function sandboxShow(Request $r, int $id)
    {
        return $this->dom(fn () => $this->sandbox->show($this->uid($r), $id));
    }

    public function sandboxSend(Request $r)
    {
        $in = $r->validate(['to' => 'nullable|array', 'cc' => 'nullable|array', 'bcc' => 'nullable|array', 'subject' => 'nullable|string|max:250', 'body' => 'nullable|string|max:50000', 'draft' => 'sometimes|boolean']);
        return $this->dom(fn () => $this->sandbox->send($this->uid($r), $in, (bool) ($in['draft'] ?? false)), 201);
    }

    public function sandboxSimulate(Request $r)
    {
        $in = $r->validate(['from' => 'nullable|email|max:190', 'from_name' => 'nullable|string|max:120', 'subject' => 'nullable|string|max:250', 'body' => 'nullable|string|max:50000']);
        return $this->dom(fn () => $this->sandbox->simulateIncoming($this->uid($r), $in), 201);
    }

    public function sandboxUpdate(Request $r, int $id)
    {
        $in = $r->validate(['is_read' => 'sometimes|boolean', 'is_starred' => 'sometimes|boolean', 'folder' => 'sometimes|string|in:inbox,trash']);
        return $this->dom(fn () => $this->sandbox->update($this->uid($r), $id, $in));
    }

    public function sandboxDelete(Request $r, int $id)
    {
        return $this->dom(fn () => ['affected' => $this->sandbox->delete($this->uid($r), $id, $r->boolean('permanent'))]);
    }

    public function sandboxReset(Request $r)
    {
        return $this->dom(function () use ($r) { $this->sandbox->reset($this->uid($r)); return null; });
    }

    /* ---------------------------------------------------------------- سجل التكاملات (بتاعي) */

    /** Rate Limit Dashboard: استهلاك مفاتيحي وتطبيقاتي. ?range=1h|24h|7d */
    public function usage(Request $r, DevUsageService $usage)
    {
        $r->validate(['range' => 'nullable|string|in:' . implode(',', DevUsageService::ranges())]);
        return $this->dom(fn () => $usage->report($this->uid($r), (string) $r->query('range', '24h')));
    }

    public function logs(Request $r)
    {
        return $this->dom(fn () => $this->logs->search(['user_id' => $this->uid($r)] + $r->only(['app_id', 'api_key_id', 'kind', 'status', 'from', 'to', 'q']), (int) $r->query('page', 1), (int) $r->query('per_page', 30)));
    }
}
