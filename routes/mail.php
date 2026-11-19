<?php

use App\Http\Controllers\Api\MailAdvancedController;
use App\Http\Controllers\Api\MailApiController;
use App\Http\Controllers\Api\MailTenantApiController;
use Illuminate\Support\Facades\Route;

/** UIPE Mail — المرحلة 1 + 3 + 4 (mail-admin). بيتحمّل من آخر routes/api.php (فبيورث prefix /api). */
Route::prefix('v1/mail')->middleware('uip.auth')->group(function () {
    Route::get('/account', [MailApiController::class, 'account'])->middleware('throttle:240,1');
    Route::get('/messages', [MailApiController::class, 'index'])->middleware('throttle:240,1');
    Route::get('/messages/{id}', [MailApiController::class, 'show'])->whereNumber('id')->middleware('throttle:240,1');
    Route::get('/threads/{threadId}', [MailApiController::class, 'thread'])->whereNumber('threadId')->middleware('throttle:240,1');

    Route::post('/messages', [MailApiController::class, 'send'])->middleware('throttle:30,1');
    Route::post('/messages/bulk', [MailApiController::class, 'bulk'])->middleware('throttle:120,1');
    Route::patch('/messages/{id}', [MailApiController::class, 'update'])->whereNumber('id')->middleware('throttle:240,1');
    Route::delete('/messages/{id}', [MailApiController::class, 'destroy'])->whereNumber('id')->middleware('throttle:120,1');
    Route::delete('/trash', [MailApiController::class, 'emptyTrash'])->middleware('throttle:10,1');

    // المرحلة 3: Undo Send / Scheduled / Confidential
    Route::post('/messages/{id}/release', [MailApiController::class, 'release'])->whereNumber('id')->middleware('throttle:60,1');
    Route::post('/messages/{id}/cancel-send', [MailApiController::class, 'cancelSend'])->whereNumber('id')->middleware('throttle:60,1');
    Route::post('/messages/{id}/unlock', [MailApiController::class, 'unlock'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/messages/{id}/revoke', [MailApiController::class, 'revoke'])->whereNumber('id')->middleware('throttle:30,1');

    Route::post('/drafts', [MailApiController::class, 'saveDraft'])->middleware('throttle:120,1');
    Route::put('/drafts/{id}', [MailApiController::class, 'updateDraft'])->whereNumber('id')->middleware('throttle:240,1');
    Route::post('/drafts/{id}/send', [MailApiController::class, 'sendDraft'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('/labels', [MailApiController::class, 'labels'])->middleware('throttle:120,1');
    Route::post('/labels', [MailApiController::class, 'storeLabel'])->middleware('throttle:30,1');
    Route::put('/labels/{id}', [MailApiController::class, 'updateLabel'])->whereNumber('id')->middleware('throttle:30,1');
    Route::delete('/labels/{id}', [MailApiController::class, 'destroyLabel'])->whereNumber('id')->middleware('throttle:30,1');

    Route::post('/attachments', [MailApiController::class, 'upload'])->middleware('throttle:30,1');
    Route::delete('/attachments/{id}', [MailApiController::class, 'deleteAttachment'])->whereNumber('id')->middleware('throttle:30,1');
    Route::get('/attachments/{id}/download', [MailApiController::class, 'download'])->whereNumber('id')->middleware('throttle:60,1');

    // المرحلة 3: الإعدادات، التوقيعات، القوالب، الفلاتر، الرد التلقائي، الاستيراد/التصدير
    Route::get('/settings', [MailAdvancedController::class, 'settings'])->middleware('throttle:120,1');
    Route::put('/settings', [MailAdvancedController::class, 'saveSettings'])->middleware('throttle:60,1');

    Route::get('/signatures', [MailAdvancedController::class, 'signatures'])->middleware('throttle:120,1');
    Route::post('/signatures', [MailAdvancedController::class, 'storeSignature'])->middleware('throttle:30,1');
    Route::put('/signatures/{id}', [MailAdvancedController::class, 'updateSignature'])->whereNumber('id')->middleware('throttle:30,1');
    Route::delete('/signatures/{id}', [MailAdvancedController::class, 'destroySignature'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('/templates', [MailAdvancedController::class, 'templates'])->middleware('throttle:120,1');
    Route::post('/templates', [MailAdvancedController::class, 'storeTemplate'])->middleware('throttle:30,1');
    Route::put('/templates/{id}', [MailAdvancedController::class, 'updateTemplate'])->whereNumber('id')->middleware('throttle:30,1');
    Route::delete('/templates/{id}', [MailAdvancedController::class, 'destroyTemplate'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('/filters', [MailAdvancedController::class, 'filters'])->middleware('throttle:120,1');
    Route::post('/filters', [MailAdvancedController::class, 'storeFilter'])->middleware('throttle:30,1');
    Route::put('/filters/{id}', [MailAdvancedController::class, 'updateFilter'])->whereNumber('id')->middleware('throttle:30,1');
    Route::patch('/filters/{id}', [MailAdvancedController::class, 'toggleFilter'])->whereNumber('id')->middleware('throttle:60,1');
    Route::delete('/filters/{id}', [MailAdvancedController::class, 'destroyFilter'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/filters/{id}/apply', [MailAdvancedController::class, 'applyFilter'])->whereNumber('id')->middleware('throttle:10,1');

    Route::get('/vacation', [MailAdvancedController::class, 'vacation'])->middleware('throttle:120,1');
    Route::put('/vacation', [MailAdvancedController::class, 'saveVacation'])->middleware('throttle:30,1');

    Route::post('/import', [MailAdvancedController::class, 'import'])->middleware('throttle:5,1');
    Route::get('/export', [MailAdvancedController::class, 'export'])->middleware('throttle:5,1');
});

/** المرحلة 4: Multi-Tenant — لوحة أدمن الجامعة (جامعة/كلية/أدمن المنصة؛ الكونترولر بيفحص الدور). */
Route::prefix('v1/mail-admin')->middleware('uip.auth')->group(function () {
    Route::get('/tenant', [MailTenantApiController::class, 'overview'])->middleware('throttle:120,1');
    Route::put('/tenant/policies', [MailTenantApiController::class, 'updatePolicies'])->middleware('throttle:30,1');

    Route::get('/domains', [MailTenantApiController::class, 'domains'])->middleware('throttle:120,1');
    Route::post('/domains', [MailTenantApiController::class, 'addDomain'])->middleware('throttle:20,1');
    Route::post('/domains/{id}/primary', [MailTenantApiController::class, 'setPrimary'])->whereNumber('id')->middleware('throttle:20,1');
    Route::delete('/domains/{id}', [MailTenantApiController::class, 'removeDomain'])->whereNumber('id')->middleware('throttle:20,1');

    Route::get('/members', [MailTenantApiController::class, 'members'])->middleware('throttle:120,1');
    Route::patch('/accounts/{id}', [MailTenantApiController::class, 'updateAccount'])->whereNumber('id')->middleware('throttle:120,1');
    Route::post('/provision', [MailTenantApiController::class, 'provision'])->middleware('throttle:5,1');

    // أدمن المنصة فقط
    Route::get('/tenants', [MailTenantApiController::class, 'allTenants'])->middleware('throttle:120,1');
    Route::post('/tenants/{universityId}/suspend', [MailTenantApiController::class, 'suspendTenant'])->whereNumber('universityId')->middleware('throttle:30,1');
    Route::post('/tenants/{universityId}/resume', [MailTenantApiController::class, 'resumeTenant'])->whereNumber('universityId')->middleware('throttle:30,1');
    Route::post('/platform-domains/{id}/activate', [MailTenantApiController::class, 'activateDomain'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/platform-domains/{id}/disable', [MailTenantApiController::class, 'disableDomain'])->whereNumber('id')->middleware('throttle:30,1');
});
