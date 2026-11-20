<?php

use App\Http\Controllers\Api\MailAccessApiController;
use App\Http\Controllers\Api\MailAdvancedController;
use App\Http\Controllers\Api\MailApiController;
use App\Http\Controllers\Api\MailIntegrationController;
use App\Http\Controllers\Api\MailSecurityApiController;
use App\Http\Controllers\Api\MailTenantApiController;
use App\Http\Controllers\Api\MailWebhookController;
use Illuminate\Support\Facades\Route;

/** UIPE Mail — المرحلة 1 + 3 + 4 (mail-admin) + 7 (mail.ip = قواعد IP على الصندوق). بيتحمّل من آخر routes/api.php (فبيورث prefix /api). */
Route::prefix('v1/mail')->middleware(['uip.auth', 'mail.ip', 'mail.access'])->group(function () {
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

/** المرحلة 6: ربط البريد بالشات / الاجتماعات / Drive / AI + إشعارات البريد. (الرسائل السرّية ممنوعة من الربط جوه الخدمة.) */
Route::prefix('v1/mail')->middleware(['uip.auth', 'mail.ip', 'mail.access'])->group(function () {
    Route::get('/messages/{id}/links', [MailIntegrationController::class, 'links'])->whereNumber('id')->middleware('throttle:120,1');
    Route::post('/messages/{id}/share-chat', [MailIntegrationController::class, 'shareChat'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/messages/{id}/meeting', [MailIntegrationController::class, 'meeting'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/messages/{id}/save-drive', [MailIntegrationController::class, 'saveDrive'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('/ai/status', [MailIntegrationController::class, 'aiStatus'])->middleware('throttle:60,1');
    Route::post('/ai/{action}', [MailIntegrationController::class, 'ai'])->middleware('throttle:30,1');
    Route::get('/notification-prefs', [MailIntegrationController::class, 'notificationPrefs'])->middleware('throttle:120,1');
    Route::put('/notification-prefs', [MailIntegrationController::class, 'saveNotificationPrefs'])->middleware('throttle:30,1');
});

/** المرحلة 7: الإدارة والأمان — أدمن الجامعة / أدمن المنصة (الكونترولر بيفحص الدور وبيمنع الكلية). */
Route::prefix('v1/mail-admin/security')->middleware('uip.auth')->group(function () {
    Route::get('/overview', [MailSecurityApiController::class, 'overview'])->middleware('throttle:120,1');

    Route::get('/events', [MailSecurityApiController::class, 'events'])->middleware('throttle:120,1');
    Route::post('/events/{id}/resolve', [MailSecurityApiController::class, 'resolveEvent'])->whereNumber('id')->middleware('throttle:60,1');

    Route::get('/audit', [MailSecurityApiController::class, 'auditLog'])->middleware('throttle:120,1');
    Route::get('/audit/export', [MailSecurityApiController::class, 'auditExport'])->middleware('throttle:5,1');

    Route::get('/ip-rules', [MailSecurityApiController::class, 'ipRules'])->middleware('throttle:120,1');
    Route::post('/ip-rules', [MailSecurityApiController::class, 'addIpRule'])->middleware('throttle:20,1');
    Route::delete('/ip-rules/{id}', [MailSecurityApiController::class, 'removeIpRule'])->whereNumber('id')->middleware('throttle:20,1');

    Route::get('/suppressions', [MailSecurityApiController::class, 'suppressions'])->middleware('throttle:120,1');
    Route::post('/suppressions', [MailSecurityApiController::class, 'addSuppression'])->middleware('throttle:30,1');
    Route::delete('/suppressions/{id}', [MailSecurityApiController::class, 'removeSuppression'])->whereNumber('id')->middleware('throttle:30,1');
    Route::get('/bounces', [MailSecurityApiController::class, 'bounces'])->middleware('throttle:120,1');

    Route::get('/queue', [MailSecurityApiController::class, 'queueStats'])->middleware('throttle:120,1');
    Route::get('/queue/deliveries', [MailSecurityApiController::class, 'deliveries'])->middleware('throttle:120,1');
    Route::post('/queue/deliveries/{id}/retry', [MailSecurityApiController::class, 'retryDelivery'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('/policy', [MailSecurityApiController::class, 'policy'])->middleware('throttle:120,1');
    Route::put('/policy', [MailSecurityApiController::class, 'updatePolicy'])->middleware('throttle:20,1');

    Route::get('/flagged-accounts', [MailSecurityApiController::class, 'flagged'])->middleware('throttle:120,1');
    Route::post('/accounts/{id}/legal-hold', [MailSecurityApiController::class, 'legalHold'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/accounts/{id}/reinstate', [MailSecurityApiController::class, 'reinstate'])->whereNumber('id')->middleware('throttle:30,1');
});

/** المرحلة 7: Webhook بتاع Brevo (bounce / delivered / spam). عام بس محمي بتوكن سري (UIPE_MAIL_WEBHOOK_SECRET). */
Route::post('v1/mail/webhooks/brevo', [MailWebhookController::class, 'brevo'])->middleware('throttle:600,1');

// UIPE Mail — إدارة الوصول (أدمن المنصة + أدمن الجامعة)
Route::prefix('v1/mail-admin/access')->middleware('uip.auth')->group(function () {
    Route::get('/overview', [MailAccessApiController::class, 'overview'])->middleware('throttle:120,1');
    Route::put('/settings', [MailAccessApiController::class, 'saveSettings'])->middleware('throttle:30,1');
    Route::put('/roles/{slug}', [MailAccessApiController::class, 'saveRole'])->where('slug', '[a-z0-9_-]+')->middleware('throttle:60,1');
    Route::get('/matrix', [MailAccessApiController::class, 'matrix'])->middleware('throttle:120,1');
    Route::put('/matrix', [MailAccessApiController::class, 'saveMatrix'])->middleware('throttle:30,1');
    Route::get('/domains', [MailAccessApiController::class, 'domains'])->middleware('throttle:120,1');
    Route::post('/domains', [MailAccessApiController::class, 'addDomain'])->middleware('throttle:30,1');
    Route::delete('/domains/{id}', [MailAccessApiController::class, 'removeDomain'])->whereNumber('id')->middleware('throttle:30,1');
    Route::get('/users', [MailAccessApiController::class, 'users'])->middleware('throttle:120,1');
    Route::post('/users', [MailAccessApiController::class, 'saveUser'])->middleware('throttle:30,1');
    Route::delete('/users/{id}', [MailAccessApiController::class, 'removeUser'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/check', [MailAccessApiController::class, 'check'])->middleware('throttle:60,1');
});
