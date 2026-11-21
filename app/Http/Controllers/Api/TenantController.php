<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDomain;
use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantService;
use Illuminate\Http\Request;

/**
 * /api/v1/developer/tenants — إدارة الـ Workspaces (Tenants) لأعضائها: بيانات، branding، أعضاء، دومينات، وموارد الـ API (apps/keys/webhooks).
 * الصلاحيات والعزل كلها جوه TenantService (authorize) — الكنترولر بيمرّر هوية المستخدم بس.
 */
class TenantController extends Controller
{
    use RunsDomain;

    public function __construct(private TenantService $tenants)
    {
    }

    public function index(Request $r)
    {
        return $this->dom(fn () => ['tenants' => $this->tenants->mine($this->uid($r))]);
    }

    public function store(Request $r)
    {
        $in = $r->validate(['name' => 'required|string|min:3|max:100', 'kind' => 'nullable|in:company,university,developer']);
        return $this->dom(fn () => $this->tenants->create($this->uid($r), $in), 201);
    }

    public function show(Request $r, int $id)
    {
        return $this->dom(fn () => $this->tenants->show($this->uid($r), $id));
    }

    public function update(Request $r, int $id)
    {
        $in = $r->validate(['name' => 'required|string|min:3|max:100']);
        return $this->dom(fn () => $this->tenants->rename($this->uid($r), $id, $in['name']));
    }

    public function destroy(Request $r, int $id)
    {
        $in = $r->validate(['confirm_slug' => 'required|string|max:60']);
        return $this->dom(function () use ($r, $id, $in) {
            $this->tenants->delete($this->uid($r), $id, $in['confirm_slug']);
            return null;
        });
    }

    /* ---- branding */
    public function branding(Request $r, int $id)
    {
        return $this->dom(fn () => ['branding' => $this->tenants->show($this->uid($r), $id)['branding']]);
    }

    public function saveBranding(Request $r, int $id)
    {
        $in = $r->validate([
            'display_name' => 'nullable|string|max:80', 'logo_url' => 'nullable|string|max:300', 'favicon_url' => 'nullable|string|max:300',
            'primary_color' => 'nullable|string|max:7', 'accent_color' => 'nullable|string|max:7', 'theme' => 'nullable|in:light,dark,auto',
            'default_locale' => 'nullable|in:en,ar', 'support_email' => 'nullable|string|max:150', 'support_url' => 'nullable|string|max:300',
            'privacy_url' => 'nullable|string|max:300', 'terms_url' => 'nullable|string|max:300', 'hide_platform_branding' => 'nullable|boolean',
        ]);
        return $this->dom(fn () => ['branding' => $this->tenants->updateBranding($this->uid($r), $id, $in)]);
    }

    /* ---- members */
    public function members(Request $r, int $id)
    {
        return $this->dom(fn () => ['members' => $this->tenants->members($this->uid($r), $id)]);
    }

    public function addMember(Request $r, int $id)
    {
        $in = $r->validate(['email' => 'required|email|max:190', 'role' => 'required|in:owner,admin,developer,viewer']);
        return $this->dom(fn () => ['members' => $this->tenants->addMember($this->uid($r), $id, $in['email'], $in['role'])], 201);
    }

    public function setRole(Request $r, int $id, int $userId)
    {
        $in = $r->validate(['role' => 'required|in:owner,admin,developer,viewer']);
        return $this->dom(fn () => ['members' => $this->tenants->setRole($this->uid($r), $id, $userId, $in['role'])]);
    }

    public function removeMember(Request $r, int $id, int $userId)
    {
        return $this->dom(function () use ($r, $id, $userId) {
            $this->tenants->removeMember($this->uid($r), $id, $userId);
            return null;
        });
    }

    /* ---- domains */
    public function domains(Request $r, int $id)
    {
        return $this->dom(fn () => ['domains' => $this->tenants->domains($this->uid($r), $id)]);
    }

    public function addDomain(Request $r, int $id)
    {
        $in = $r->validate(['domain' => 'required|string|max:253']);
        return $this->dom(fn () => ['domain' => $this->tenants->addDomain($this->uid($r), $id, $in['domain'])], 201);
    }

    public function verifyDomain(Request $r, int $id, int $domainId)
    {
        return $this->dom(fn () => ['domain' => $this->tenants->verifyDomain($this->uid($r), $id, $domainId)]);
    }

    public function primaryDomain(Request $r, int $id, int $domainId)
    {
        return $this->dom(fn () => ['domain' => $this->tenants->primaryDomain($this->uid($r), $id, $domainId)]);
    }

    public function removeDomain(Request $r, int $id, int $domainId)
    {
        return $this->dom(function () use ($r, $id, $domainId) {
            $this->tenants->removeDomain($this->uid($r), $id, $domainId);
            return null;
        });
    }

    /* ---- resources (isolated by tenant_id) */
    public function apps(Request $r, int $id)
    {
        return $this->dom(fn () => ['apps' => $this->tenants->listApps($this->uid($r), $id)]);
    }

    public function createApp(Request $r, int $id)
    {
        return $this->dom(fn () => $this->tenants->createApp($this->uid($r), $id, $r->all()), 201);
    }

    public function deleteApp(Request $r, int $id, int $appId)
    {
        return $this->dom(function () use ($r, $id, $appId) {
            $this->tenants->deleteApp($this->uid($r), $id, $appId);
            return null;
        });
    }

    public function keys(Request $r, int $id)
    {
        return $this->dom(fn () => ['keys' => $this->tenants->listKeys($this->uid($r), $id)]);
    }

    public function createKey(Request $r, int $id)
    {
        return $this->dom(fn () => ['key' => $this->tenants->createKey($this->uid($r), $id, $r->all())], 201);
    }

    public function revokeKey(Request $r, int $id, int $keyId)
    {
        return $this->dom(function () use ($r, $id, $keyId) {
            $this->tenants->revokeKey($this->uid($r), $id, $keyId);
            return null;
        });
    }

    public function webhooks(Request $r, int $id)
    {
        return $this->dom(fn () => ['webhooks' => $this->tenants->listWebhooks($this->uid($r), $id)]);
    }

    public function createWebhook(Request $r, int $id)
    {
        return $this->dom(fn () => ['webhook' => $this->tenants->createWebhook($this->uid($r), $id, $r->all())], 201);
    }

    public function deleteWebhook(Request $r, int $id, int $hookId)
    {
        return $this->dom(function () use ($r, $id, $hookId) {
            $this->tenants->deleteWebhook($this->uid($r), $id, $hookId);
            return null;
        });
    }

    /* ---- participants (المستخدم النهائي عند العميل) */
    public function participants(Request $r, int $id)
    {
        $f = $r->validate(['q' => 'nullable|string|max:100', 'role' => 'nullable|string|max:12', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return $this->dom(fn () => $this->tenants->listParticipants($this->uid($r), $id, $f));
    }

    public function saveParticipant(Request $r, int $id)
    {
        $in = $r->validate(['external_id' => 'required|string|max:128', 'name' => 'nullable|string|max:160', 'email' => 'nullable|string|max:190', 'role' => 'nullable|string|max:12', 'meta' => 'nullable|array']);
        $in = array_intersect_key($in, $r->all());   // الحقل اللي ماتبعتش مايتغيرش (upsert جزئي)
        return $this->dom(function () use ($r, $id, $in) {
            return $this->tenants->saveParticipant($this->uid($r), $id, $in);
        });
    }

    public function deleteParticipant(Request $r, int $id, string $externalId)
    {
        return $this->dom(function () use ($r, $id, $externalId) {
            $this->tenants->deleteParticipant($this->uid($r), $id, $externalId);
            return null;
        });
    }

    /* ---- embed: النطاقات المسموح تضمّن فيها الـ iframe */
    public function embedOrigins(Request $r, int $id)
    {
        return $this->dom(fn () => $this->tenants->embedOrigins($this->uid($r), $id));
    }

    public function addEmbedOrigin(Request $r, int $id)
    {
        $in = $r->validate(['origin' => 'required|string|max:190']);
        return $this->dom(fn () => ['origin' => $this->tenants->addEmbedOrigin($this->uid($r), $id, $in['origin'])] + $this->tenants->embedOrigins($this->uid($r), $id), 201);
    }

    public function removeEmbedOrigin(Request $r, int $id, int $originId)
    {
        return $this->dom(function () use ($r, $id, $originId) {
            $this->tenants->removeEmbedOrigin($this->uid($r), $id, $originId);
            return null;
        });
    }

    /* ---- usage + logs (isolated per tenant) */
    public function usage(Request $r, int $id)
    {
        $r->validate(['range' => 'nullable|string|in:' . implode(',', \App\Services\Developer\DevUsageService::ranges())]);
        return $this->dom(fn () => $this->tenants->usageReport($this->uid($r), $id, (string) $r->query('range', '24h')));
    }

    public function logs(Request $r, int $id)
    {
        $f = $r->validate(['kind' => 'nullable|in:api,oauth,admin,webhook,sandbox', 'status' => 'nullable|string|max:8', 'from' => 'nullable|string|max:40', 'to' => 'nullable|string|max:40',
            'q' => 'nullable|string|max:100', 'app_id' => 'nullable|integer', 'api_key_id' => 'nullable|integer', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return $this->dom(fn () => $this->tenants->logs($this->uid($r), $id, $f, (int) ($f['page'] ?? 1), (int) ($f['per_page'] ?? 30)));
    }
}
