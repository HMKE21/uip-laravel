<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsTenantApi;
use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantService;
use Illuminate\Http\Request;

/**
 * Developer API — المشاركين الخارجيين (المستخدم النهائي عند العميل) + توكن دخول الـ Embed.
 * مفتاح tenant بس (live). المفتاح الشخصي بيتردّ بـ 403 tenant_required.
 */
class DevParticipantController extends Controller
{
    use RunsTenantApi;

    public function __construct(private TenantService $tenants)
    {
    }

    public function index(Request $r)
    {
        if (!($tid = $this->tenantIdOf($r))) {
            return $this->tenantRequired();
        }
        $f = $r->validate(['q' => 'nullable|string|max:100', 'role' => 'nullable|string|max:12', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return $this->runApi(fn () => $this->tenants->apiListParticipants($tid, $f));
    }

    public function show(Request $r, string $external_id)
    {
        if (!($tid = $this->tenantIdOf($r))) {
            return $this->tenantRequired();
        }
        return $this->runApi(fn () => $this->tenants->apiGetParticipant($tid, $external_id));
    }

    public function upsert(Request $r, string $external_id)
    {
        if (!($tid = $this->tenantIdOf($r))) {
            return $this->tenantRequired();
        }
        $in = $r->validate(['name' => 'nullable|string|max:160', 'email' => 'nullable|string|max:190', 'role' => 'nullable|string|max:12', 'meta' => 'nullable|array']);
        $in = array_intersect_key($in, $r->all()) + ['external_id' => $external_id];
        try {
            $out = $this->tenants->apiSaveParticipant($tid, $in);
        } catch (\DomainException $e) {
            return $this->runApi(fn () => throw $e);
        }
        return $this->apiSuccess($out['participant'], 'OK', $out['created'] ? 201 : 200);
    }

    public function destroy(Request $r, string $external_id)
    {
        if (!($tid = $this->tenantIdOf($r))) {
            return $this->tenantRequired();
        }
        return $this->runApi(function () use ($tid, $external_id) {
            $this->tenants->apiDeleteParticipant($tid, $external_id);
            return null;
        });
    }

    /** POST /embed/sessions — سيرفر العميل بيطلب توكن دخول (مرة واحدة) لمشارك. */
    public function embedSession(Request $r)
    {
        if (!($tid = $this->tenantIdOf($r))) {
            return $this->tenantRequired();
        }
        $in = $r->validate([
            'external_id' => 'required|string|max:128', 'name' => 'nullable|string|max:160', 'email' => 'nullable|string|max:190', 'role' => 'nullable|string|max:12', 'meta' => 'nullable|array',
            'room' => 'nullable|string|max:128', 'origin' => 'nullable|string|max:190', 'ttl' => 'nullable|integer|min:15|max:3600',
        ]);
        $in = array_intersect_key($in, $r->all());
        return $this->runApi(fn () => $this->tenants->apiCreateEmbedSession($tid, $in), 201);
    }
}
