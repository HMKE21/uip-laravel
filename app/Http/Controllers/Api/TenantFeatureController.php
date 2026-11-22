<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantFeatures;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/v1/tenant/features — جوه العميل بس (uip.scope:tenant).
 *  GET    /                 كل مستخدم مسجّل: حالة الميزات (الفرونت يخبّي/يظهر القوايم منها).
 *  POST   /request          أدمن العميل: يطلب ميزة أو أكتر.
 *  DELETE /request/{feature} أدمن العميل: يلغي طلب لسه pending.
 */
class TenantFeatureController extends Controller
{
    public function index(Request $r)
    {
        $tid = (int) TenantContext::id();
        $state = TenantFeatures::stateFor($tid);
        return $this->apiSuccess([
            'features' => $state,
            'enabled'  => TenantFeatures::enabledKeys($tid),
        ]);
    }

    public function request(Request $r)
    {
        $in = $r->validate([
            'features'   => 'required|array|min:1|max:30',
            'features.*' => ['required', 'string', Rule::in(array_keys(TenantFeatures::catalog()))],
            'note'       => 'nullable|string|max:255',
        ]);
        $res = TenantFeatures::request((int) TenantContext::id(), $in['features'], (int) $r->attributes->get('uip_user_id'), $in['note'] ?? null);
        if (!empty($res['requested'])) {
            \App\Services\Tenancy\TenantFeatureNotifier::requested((int) TenantContext::id(), $res['requested'], (int) $r->attributes->get('uip_user_id'), $in['note'] ?? null);
        }
        return $this->apiSuccess($res + ['features' => TenantFeatures::stateFor((int) TenantContext::id())], 'Request submitted.', 201);
    }

    public function cancel(Request $r, string $feature)
    {
        if (!array_key_exists($feature, TenantFeatures::catalog())) {
            return $this->apiError('Unknown feature.', null, 404);
        }
        $ok = TenantFeatures::cancelRequest((int) TenantContext::id(), $feature);
        return $ok ? $this->apiSuccess(['feature' => $feature], 'Request cancelled.') : $this->apiError('No pending request for this feature.', null, 404);
    }
}
