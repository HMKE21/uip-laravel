<?php

use App\Http\Controllers\Api\DevAdminController;
use App\Http\Controllers\Api\DevApiController;
use App\Http\Controllers\Api\DeveloperCenterController;
use App\Http\Controllers\Api\OAuthTokenController;
use Illuminate\Support\Facades\Route;

/** UIPE Developer Center — المرحلة 6. بيتحمّل من آخر routes/api.php (فبيورث prefix /api). */

// 1) OAuth token/revoke — عام (Client auth جوه الخدمة). مستثنى من التشفير ومن CORS allowlist (Bearer بس).
Route::prefix('oauth')->middleware('throttle:60,1')->group(function () {
    Route::post('/token', [OAuthTokenController::class, 'token']);
    Route::post('/revoke', [OAuthTokenController::class, 'revoke']);
});

// 2) Developer Center لأي مستخدم مسجّل دخول (JWT المنصة)
Route::prefix('v1/developer')->middleware('uip.auth')->group(function () {
    Route::get('/overview', [DeveloperCenterController::class, 'overview'])->middleware('throttle:120,1');
    Route::get('/access', [DeveloperCenterController::class, 'access'])->middleware('throttle:120,1');
    Route::post('/access/request', [DeveloperCenterController::class, 'requestAccess'])->middleware('throttle:10,1');

    Route::get('/apps', [DeveloperCenterController::class, 'apps'])->middleware('throttle:120,1');
    Route::post('/apps', [DeveloperCenterController::class, 'createApp'])->middleware('throttle:20,1');
    Route::get('/apps/{id}', [DeveloperCenterController::class, 'app'])->whereNumber('id')->middleware('throttle:120,1');
    Route::put('/apps/{id}', [DeveloperCenterController::class, 'updateApp'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/apps/{id}/rotate-secret', [DeveloperCenterController::class, 'rotateAppSecret'])->whereNumber('id')->middleware('throttle:10,1');
    Route::post('/apps/{id}/status', [DeveloperCenterController::class, 'setAppStatus'])->whereNumber('id')->middleware('throttle:30,1');
    Route::delete('/apps/{id}', [DeveloperCenterController::class, 'deleteApp'])->whereNumber('id')->middleware('throttle:20,1');

    Route::get('/keys', [DeveloperCenterController::class, 'keys'])->middleware('throttle:120,1');
    Route::post('/keys', [DeveloperCenterController::class, 'createKey'])->middleware('throttle:20,1');
    Route::put('/keys/{id}', [DeveloperCenterController::class, 'updateKey'])->whereNumber('id')->middleware('throttle:30,1');
    Route::delete('/keys/{id}', [DeveloperCenterController::class, 'revokeKey'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('/grants', [DeveloperCenterController::class, 'grants'])->middleware('throttle:120,1');
    Route::delete('/grants/{id}', [DeveloperCenterController::class, 'revokeGrant'])->whereNumber('id')->middleware('throttle:30,1');

    // شاشة موافقة OAuth (React: /oauth/authorize) — الباراميترز نفسها بتاعة /authorize القياسية
    Route::get('/oauth/authorize', [DeveloperCenterController::class, 'authorizeInfo'])->middleware('throttle:60,1');
    Route::post('/oauth/authorize', [DeveloperCenterController::class, 'authorizeDecide'])->middleware('throttle:30,1');

    Route::get('/webhooks', [DeveloperCenterController::class, 'webhooks'])->middleware('throttle:120,1');
    Route::post('/webhooks', [DeveloperCenterController::class, 'createWebhook'])->middleware('throttle:20,1');
    Route::put('/webhooks/{id}', [DeveloperCenterController::class, 'updateWebhook'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/webhooks/{id}/active', [DeveloperCenterController::class, 'setWebhookActive'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/webhooks/{id}/rotate-secret', [DeveloperCenterController::class, 'rotateWebhookSecret'])->whereNumber('id')->middleware('throttle:10,1');
    Route::post('/webhooks/{id}/test', [DeveloperCenterController::class, 'testWebhook'])->whereNumber('id')->middleware('throttle:20,1');
    Route::delete('/webhooks/{id}', [DeveloperCenterController::class, 'deleteWebhook'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('/webhooks/{id}/deliveries', [DeveloperCenterController::class, 'webhookDeliveries'])->whereNumber('id')->middleware('throttle:120,1');
    Route::get('/deliveries/{id}', [DeveloperCenterController::class, 'delivery'])->whereNumber('id')->middleware('throttle:120,1');
    Route::post('/deliveries/{id}/redeliver', [DeveloperCenterController::class, 'redeliver'])->whereNumber('id')->middleware('throttle:20,1');

    Route::get('/sandbox', [DeveloperCenterController::class, 'sandboxMe'])->middleware('throttle:120,1');
    Route::get('/sandbox/messages', [DeveloperCenterController::class, 'sandboxList'])->middleware('throttle:120,1');
    Route::get('/sandbox/messages/{id}', [DeveloperCenterController::class, 'sandboxShow'])->whereNumber('id')->middleware('throttle:120,1');
    Route::post('/sandbox/messages', [DeveloperCenterController::class, 'sandboxSend'])->middleware('throttle:60,1');
    Route::patch('/sandbox/messages/{id}', [DeveloperCenterController::class, 'sandboxUpdate'])->whereNumber('id')->middleware('throttle:120,1');
    Route::delete('/sandbox/messages/{id}', [DeveloperCenterController::class, 'sandboxDelete'])->whereNumber('id')->middleware('throttle:120,1');
    Route::post('/sandbox/simulate-incoming', [DeveloperCenterController::class, 'sandboxSimulate'])->middleware('throttle:60,1');
    Route::post('/sandbox/reset', [DeveloperCenterController::class, 'sandboxReset'])->middleware('throttle:10,1');

    Route::get('/logs', [DeveloperCenterController::class, 'logs'])->middleware('throttle:120,1');
});

// 3) إدارة الجامعة / المنصة — تحكم كامل (السياسة، القواعد، المطورين، التطبيقات، المفاتيح، الـ webhooks، السجل)
Route::prefix('v1/dev-admin')->middleware('uip.auth')->group(function () {
    Route::get('/meta', [DevAdminController::class, 'meta'])->middleware('throttle:120,1');

    Route::get('/policy', [DevAdminController::class, 'policy'])->middleware('throttle:120,1');
    Route::put('/policy', [DevAdminController::class, 'savePolicy'])->middleware('throttle:30,1');
    Route::post('/policy/check', [DevAdminController::class, 'checkAccess'])->middleware('throttle:60,1');

    Route::get('/rules', [DevAdminController::class, 'rules'])->middleware('throttle:120,1');
    Route::post('/rules', [DevAdminController::class, 'addRule'])->middleware('throttle:60,1');
    Route::put('/rules/{id}', [DevAdminController::class, 'updateRule'])->whereNumber('id')->middleware('throttle:60,1');
    Route::delete('/rules/{id}', [DevAdminController::class, 'deleteRule'])->whereNumber('id')->middleware('throttle:60,1');

    Route::get('/developers', [DevAdminController::class, 'developers'])->middleware('throttle:120,1');
    Route::post('/developers/bulk', [DevAdminController::class, 'bulk'])->middleware('throttle:20,1');
    Route::get('/developers/{userId}', [DevAdminController::class, 'developer'])->whereNumber('userId')->middleware('throttle:120,1');
    Route::put('/developers/{userId}/access', [DevAdminController::class, 'setAccess'])->whereNumber('userId')->middleware('throttle:60,1');
    Route::delete('/developers/{userId}/access', [DevAdminController::class, 'clearAccess'])->whereNumber('userId')->middleware('throttle:60,1');
    Route::post('/developers/{userId}/decision', [DevAdminController::class, 'decide'])->whereNumber('userId')->middleware('throttle:60,1');
    Route::post('/developers/{userId}/revoke-all', [DevAdminController::class, 'revokeAll'])->whereNumber('userId')->middleware('throttle:20,1');

    Route::get('/apps', [DevAdminController::class, 'apps'])->middleware('throttle:120,1');
    Route::get('/apps/{id}', [DevAdminController::class, 'app'])->whereNumber('id')->middleware('throttle:120,1');
    Route::put('/apps/{id}', [DevAdminController::class, 'updateApp'])->whereNumber('id')->middleware('throttle:30,1');
    Route::delete('/apps/{id}', [DevAdminController::class, 'deleteApp'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/apps/{id}/suspend', [DevAdminController::class, 'suspend'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/apps/{id}/resume', [DevAdminController::class, 'resume'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('/keys', [DevAdminController::class, 'keys'])->middleware('throttle:120,1');
    Route::delete('/keys/{id}', [DevAdminController::class, 'revokeKey'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('/webhooks', [DevAdminController::class, 'webhooks'])->middleware('throttle:120,1');
    Route::post('/webhooks/{id}/active', [DevAdminController::class, 'setWebhookActive'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('/logs', [DevAdminController::class, 'logs'])->middleware('throttle:120,1');
});

// 4) Developer API العام: Bearer uipk_… / uipo_… (+ scope). الـ middleware dev.auth بيتحط على كل route (مرة واحدة) والـ rate limit جواه لكل مفتاح/توكن.
Route::prefix('dev/v1')->group(function () {
    Route::get('/me', [DevApiController::class, 'me'])->middleware('dev.auth:profile.read');
    Route::get('/scopes', [DevApiController::class, 'scopes'])->middleware('dev.auth');

    Route::get('/mail/folders', [DevApiController::class, 'folders'])->middleware('dev.auth:mail.read');
    Route::get('/mail/messages', [DevApiController::class, 'messages'])->middleware('dev.auth:mail.read');
    Route::get('/mail/messages/{id}', [DevApiController::class, 'message'])->whereNumber('id')->middleware('dev.auth:mail.read');
    Route::get('/mail/threads/{threadId}', [DevApiController::class, 'thread'])->whereNumber('threadId')->middleware('dev.auth:mail.read');
    Route::post('/mail/messages', [DevApiController::class, 'send'])->middleware('dev.auth:mail.send');
    Route::post('/mail/drafts', [DevApiController::class, 'saveDraft'])->middleware('dev.auth:mail.drafts');
    Route::put('/mail/drafts/{id}', [DevApiController::class, 'updateDraft'])->whereNumber('id')->middleware('dev.auth:mail.drafts');
    Route::post('/mail/drafts/{id}/send', [DevApiController::class, 'sendDraft'])->whereNumber('id')->middleware('dev.auth:mail.send');
    Route::patch('/mail/messages/{id}', [DevApiController::class, 'update'])->whereNumber('id')->middleware('dev.auth:mail.modify');
    Route::delete('/mail/messages/{id}', [DevApiController::class, 'destroy'])->whereNumber('id')->middleware('dev.auth:mail.modify');

    Route::get('/mail/labels', [DevApiController::class, 'labels'])->middleware('dev.auth:mail.read');
    Route::post('/mail/labels', [DevApiController::class, 'storeLabel'])->middleware('dev.auth:mail.labels');
    Route::put('/mail/labels/{id}', [DevApiController::class, 'updateLabel'])->whereNumber('id')->middleware('dev.auth:mail.labels');
    Route::delete('/mail/labels/{id}', [DevApiController::class, 'destroyLabel'])->whereNumber('id')->middleware('dev.auth:mail.labels');

    Route::post('/mail/messages/{id}/share-chat', [DevApiController::class, 'shareChat'])->whereNumber('id')->middleware('dev.auth:chat.write');
    Route::post('/mail/messages/{id}/meeting', [DevApiController::class, 'meeting'])->whereNumber('id')->middleware('dev.auth:meetings.write');
    Route::post('/mail/messages/{id}/save-drive', [DevApiController::class, 'saveDrive'])->whereNumber('id')->middleware('dev.auth:drive.write');
    Route::post('/mail/ai/{action}', [DevApiController::class, 'ai'])->middleware('dev.auth:ai.use');
    Route::post('/notifications', [DevApiController::class, 'notify'])->middleware('dev.auth:notifications.write');

    Route::get('/webhooks', [DevApiController::class, 'webhooks'])->middleware('dev.auth:webhooks.manage');
    Route::post('/webhooks', [DevApiController::class, 'createWebhook'])->middleware('dev.auth:webhooks.manage');
    Route::delete('/webhooks/{id}', [DevApiController::class, 'deleteWebhook'])->whereNumber('id')->middleware('dev.auth:webhooks.manage');

    Route::post('/sandbox/simulate-incoming', [DevApiController::class, 'sandboxSimulate'])->middleware('dev.auth:mail.read');
    Route::post('/sandbox/reset', [DevApiController::class, 'sandboxReset'])->middleware('dev.auth:mail.read');
});
