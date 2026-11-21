<?php

use App\Http\Controllers\Api\TenantFeatureAdminController;
use App\Http\Controllers\Api\TenantFeatureController;
use Illuminate\Support\Facades\Route;

// White-label tenancy — الخطوة 4: ميزات كل عميل.

// 1) جوه العميل (core: بادئة 'tenant' مش مقفولة). القراءة لأي مستخدم مسجّل، والطلب/الإلغاء لأدمن العميل.
Route::prefix('v1/tenant/features')->middleware(['uip.scope:tenant', 'uip.auth'])->group(function () {
    Route::get('/', [TenantFeatureController::class, 'index'])->middleware('throttle:120,1');
    Route::post('/request', [TenantFeatureController::class, 'request'])->middleware(['uip.admin', 'throttle:20,1']);
    Route::delete('/request/{feature}', [TenantFeatureController::class, 'cancel'])->middleware(['uip.admin', 'throttle:20,1']);
});

// 2) أدمن المنصة الأم بس.
Route::prefix('v1/dev-admin')->middleware(['uip.scope:platform', 'uip.auth', 'uip.admin'])->group(function () {
    Route::get('/tenant-features/requests', [TenantFeatureAdminController::class, 'requests'])->middleware('throttle:120,1');
    Route::get('/tenants/{id}/features', [TenantFeatureAdminController::class, 'show'])->whereNumber('id')->middleware('throttle:120,1');
    Route::put('/tenants/{id}/features', [TenantFeatureAdminController::class, 'bulk'])->whereNumber('id')->middleware('throttle:30,1');
    Route::put('/tenants/{id}/features/{feature}', [TenantFeatureAdminController::class, 'decide'])->whereNumber('id')->middleware('throttle:60,1');
});
