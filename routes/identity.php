<?php

use App\Http\Controllers\Api\Auth\PasskeyController;
use App\Http\Controllers\Api\IdentityApiController;
use App\Http\Controllers\Api\ProvisioningApiController;
use App\Http\Controllers\Api\ScimController;
use Illuminate\Support\Facades\Route;

/** UIPE Mail — المرحلة 5 (الهوية). بيتحمّل من آخر routes/api.php (فبيورث prefix /api). */
// Passkeys — عام (الدخول)
Route::prefix('v1/auth/passkeys')->group(function () {
    Route::get('/config', [PasskeyController::class, 'config'])->middleware('throttle:60,1');
    Route::post('/login/options', [PasskeyController::class, 'loginOptions'])->middleware('throttle:30,1');
    Route::post('/login', [PasskeyController::class, 'loginSubmit'])->middleware('throttle:20,1');
});

Route::prefix('v1/auth')->middleware('uip.auth')->group(function () {
    // Passkeys — إدارة (بتوكن)
    Route::get('/passkeys', [PasskeyController::class, 'index'])->middleware('throttle:60,1');
    Route::post('/passkeys/register/options', [PasskeyController::class, 'registerOptions'])->middleware('throttle:20,1');
    Route::post('/passkeys/register', [PasskeyController::class, 'register'])->middleware('throttle:10,1');
    Route::patch('/passkeys/{id}', [PasskeyController::class, 'rename'])->whereNumber('id')->middleware('throttle:30,1');
    Route::delete('/passkeys/{id}', [PasskeyController::class, 'destroy'])->whereNumber('id')->middleware('throttle:30,1');
    Route::get('/login-history', [IdentityApiController::class, 'myLoginHistory'])->middleware('throttle:60,1');
    Route::post('/sessions/revoke-others', [IdentityApiController::class, 'revokeOthers'])->middleware('throttle:10,1');
    Route::post('/sessions/revoke-all', [IdentityApiController::class, 'revokeAll'])->middleware('throttle:5,1');
});

Route::prefix('v1/identity')->middleware('uip.auth')->group(function () {
    Route::get('/login-history', [IdentityApiController::class, 'loginHistory'])->middleware('throttle:120,1');
    Route::post('/users/{id}/revoke-tokens', [IdentityApiController::class, 'revokeUserTokens'])->whereNumber('id')->middleware('throttle:30,1');
});

// لوحة الجامعة: استيراد CSV + SCIM tokens + تعليق الصناديق عند انتهاء العضوية
Route::prefix('v1/mail-admin')->middleware('uip.auth')->group(function () {
    Route::get('/provisioning/template', [ProvisioningApiController::class, 'template'])->middleware('throttle:30,1');
    Route::post('/provisioning/import', [ProvisioningApiController::class, 'importFile'])->middleware('throttle:10,1');
    Route::get('/provisioning/runs', [ProvisioningApiController::class, 'runs'])->middleware('throttle:60,1');
    Route::get('/scim/tokens', [ProvisioningApiController::class, 'scimTokens'])->middleware('throttle:60,1');
    Route::post('/scim/tokens', [ProvisioningApiController::class, 'createScimToken'])->middleware('throttle:10,1');
    Route::delete('/scim/tokens/{id}', [ProvisioningApiController::class, 'revokeScimToken'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/membership/sweep', [ProvisioningApiController::class, 'sweep'])->middleware('throttle:10,1');
    Route::put('/accounts/{id}/expiry', [ProvisioningApiController::class, 'setExpiry'])->whereNumber('id')->middleware('throttle:60,1');
});

// SCIM 2.0 (للـ IdP) — Bearer token بتاع الـ tenant، مش uip.auth. المسار: /api/scim/v2
Route::prefix('scim/v2')->middleware('throttle:300,1')->group(function () {
    Route::get('/ServiceProviderConfig', [ScimController::class, 'serviceProviderConfig']);
    Route::get('/ResourceTypes', [ScimController::class, 'resourceTypes']);
    Route::get('/Schemas', [ScimController::class, 'schemas']);
    Route::get('/Users', [ScimController::class, 'index']);
    Route::post('/Users', [ScimController::class, 'store']);
    Route::get('/Users/{id}', [ScimController::class, 'show']);
    Route::put('/Users/{id}', [ScimController::class, 'replace']);
    Route::patch('/Users/{id}', [ScimController::class, 'patch']);
    Route::delete('/Users/{id}', [ScimController::class, 'destroy']);
});
