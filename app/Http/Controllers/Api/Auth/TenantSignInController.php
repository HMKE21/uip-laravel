<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantAuthSettings;
use App\Services\Tenancy\TenantContext;

/** GET /auth/tenant-sign-in — شكل صفحة دخول المنصة المعزولة (القالب والنصوص). مفيش أي secret هنا. */
class TenantSignInController extends Controller
{
    public function show()
    {
        $s = TenantAuthSettings::active();
        $data = $s === null || !TenantContext::active()
            ? ['isolated' => false]
            : ['isolated' => true, 'template' => $s['template'], 'headline' => $s['headline'], 'subtitle' => $s['subtitle']];
        return response()->json(['success' => true, 'message' => 'OK', 'data' => $data, 'errors' => null, 'meta' => (object) []]);
    }
}
