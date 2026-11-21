<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDomain;
use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantService;
use Illuminate\Http\Request;

/** /api/v1/dev-admin/tenants — أدمن المنصة بس: قايمة الـ Workspaces، توقيف/تشغيل، خطة، features (white_label, custom_domain) وحدود. */
class TenantAdminController extends Controller
{
    use RunsDomain;

    public function __construct(private TenantService $tenants)
    {
    }

    private function admin(Request $r): ?\Illuminate\Http\JsonResponse
    {
        return (string) $r->attributes->get('uip_role') === 'admin' ? null : $this->apiError('Platform admins only.', null, 403);
    }

    public function index(Request $r)
    {
        if ($deny = $this->admin($r)) {
            return $deny;
        }
        return $this->dom(fn () => $this->tenants->adminList($r->query('q') ? (string) $r->query('q') : null, $r->query('status') ? (string) $r->query('status') : null, (int) $r->query('page', 1), (int) $r->query('per_page', 30)));
    }

    public function show(Request $r, int $id)
    {
        if ($deny = $this->admin($r)) {
            return $deny;
        }
        return $this->dom(fn () => $this->tenants->adminShow($id));
    }

    public function update(Request $r, int $id)
    {
        if ($deny = $this->admin($r)) {
            return $deny;
        }
        $in = $r->validate([
            'status' => 'nullable|in:active,suspended', 'suspended_reason' => 'nullable|string|max:190', 'plan' => 'nullable|string|max:20',
            'features' => 'nullable|array', 'features.*' => 'string|max:30', 'limits' => 'nullable|array',
        ]);
        return $this->dom(fn () => ['tenant' => $this->tenants->adminUpdate($this->uid($r), $id, $in)]);
    }
}
