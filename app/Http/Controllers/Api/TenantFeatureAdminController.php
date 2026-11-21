<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantFeatures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * أدمن المنصة بس (uip.scope:platform + uip.admin) — /api/v1/dev-admin/...
 *  GET  /tenant-features/requests?status=pending|enabled|rejected|disabled|all   صندوق الطلبات
 *  GET  /tenants/{id}/features                                                   حالة ميزات عميل
 *  PUT  /tenants/{id}/features/{feature}   {status: enabled|disabled|rejected|pending, note}
 *  PUT  /tenants/{id}/features             {features: {messaging: "enabled", meetings: "disabled"}, note}
 */
class TenantFeatureAdminController extends Controller
{
    private const DECISIONS = ['enabled', 'disabled', 'rejected', 'pending'];

    private function isolatedTenant(int $id): ?object
    {
        return DB::connection(TenantContext::centralConnection())->table('tenants')->where('id', $id)->where('mode', 'isolated')->first();
    }

    public function requests(Request $r)
    {
        $status = (string) $r->query('status', 'pending');
        if (!in_array($status, ['pending', 'enabled', 'rejected', 'disabled', 'all'], true)) {
            return $this->apiError('Invalid status filter.', null, 422);
        }
        return $this->apiSuccess(['requests' => TenantFeatures::listRequests($status)]);
    }

    public function show(Request $r, int $id)
    {
        if (!$this->isolatedTenant($id)) {
            return $this->apiError('Tenant not found.', null, 404);
        }
        return $this->apiSuccess(['features' => TenantFeatures::stateFor($id), 'enabled' => TenantFeatures::enabledKeys($id)]);
    }

    public function decide(Request $r, int $id, string $feature)
    {
        if (!$this->isolatedTenant($id)) {
            return $this->apiError('Tenant not found.', null, 404);
        }
        if (!array_key_exists($feature, TenantFeatures::catalog())) {
            return $this->apiError('Unknown feature.', null, 404);
        }
        $in = $r->validate(['status' => ['required', Rule::in(self::DECISIONS)], 'note' => 'nullable|string|max:255']);
        TenantFeatures::decide($id, $feature, $in['status'], (int) $r->attributes->get('uip_user_id'), $in['note'] ?? null);
        return $this->apiSuccess(['features' => TenantFeatures::stateFor($id)], 'Feature updated.');
    }

    public function bulk(Request $r, int $id)
    {
        if (!$this->isolatedTenant($id)) {
            return $this->apiError('Tenant not found.', null, 404);
        }
        $in = $r->validate(['features' => 'required|array|min:1|max:30', 'note' => 'nullable|string|max:255']);
        $catalog = array_keys(TenantFeatures::catalog());
        foreach ($in['features'] as $feature => $status) { // نتحقق من الكل قبل ما نكتب أي حاجة
            if (!in_array((string) $feature, $catalog, true) || !in_array($status, self::DECISIONS, true)) {
                return $this->apiError("Invalid feature or status: {$feature}.", null, 422);
            }
        }
        $admin = (int) $r->attributes->get('uip_user_id');
        foreach ($in['features'] as $feature => $status) {
            TenantFeatures::decide($id, (string) $feature, (string) $status, $admin, $in['note'] ?? null);
        }
        return $this->apiSuccess(['features' => TenantFeatures::stateFor($id)], 'Features updated.');
    }
}
