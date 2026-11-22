<?php

use App\Http\Controllers\Api\DevAdminController;
use App\Http\Controllers\Api\DevApiController;
use App\Http\Controllers\Api\DevParticipantController;
use App\Http\Controllers\Api\EmbedPublicController;
use App\Http\Controllers\Api\DevDocsController;
use App\Http\Controllers\Api\DevSdkDownloadController;
use App\Http\Controllers\Api\TenantAdminController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TenantPublicController;
use App\Http\Controllers\Api\TenantSiteController;
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
    Route::get('/usage', [DeveloperCenterController::class, 'usage'])->middleware('throttle:60,1');
    Route::post('/access/request', [DeveloperCenterController::class, 'requestAccess'])->middleware('throttle:10,1');

    Route::get('/apps', [DeveloperCenterController::class, 'apps'])->middleware('throttle:120,1');
    Route::post('/apps', [DeveloperCenterController::class, 'createApp'])->middleware('throttle:20,1');
    Route::get('/apps/{id}', [DeveloperCenterController::class, 'app'])->whereNumber('id')->middleware('throttle:120,1');
    Route::put('/apps/{id}', [DeveloperCenterController::class, 'updateApp'])->whereNumber('id')->middleware('throttle:30,1');
    Route::get('/apps/{id}/review', [DeveloperCenterController::class, 'reviewStatus'])->whereNumber('id')->middleware('throttle:120,1');
    Route::post('/apps/{id}/review', [DeveloperCenterController::class, 'submitReview'])->whereNumber('id')->middleware('throttle:10,1');
    Route::delete('/apps/{id}/review', [DeveloperCenterController::class, 'withdrawReview'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/apps/{id}/rotate-secret', [DeveloperCenterController::class, 'rotateAppSecret'])->whereNumber('id')->middleware('throttle:10,1');
    Route::post('/apps/{id}/status', [DeveloperCenterController::class, 'setAppStatus'])->whereNumber('id')->middleware('throttle:30,1');
    Route::delete('/apps/{id}', [DeveloperCenterController::class, 'deleteApp'])->whereNumber('id')->middleware('throttle:20,1');

    Route::get('/keys', [DeveloperCenterController::class, 'keys'])->middleware('throttle:120,1');
    Route::post('/keys', [DeveloperCenterController::class, 'createKey'])->middleware('throttle:20,1');
    Route::put('/keys/{id}', [DeveloperCenterController::class, 'updateKey'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/keys/{id}/rotate', [DeveloperCenterController::class, 'rotateKey'])->whereNumber('id')->middleware('throttle:10,1');
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
    Route::post('/webhooks/{id}/verify-signature', [DeveloperCenterController::class, 'verifyWebhookSignature'])->whereNumber('id')->middleware('throttle:60,1');
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

    // Workspaces (Tenants) — الخطوة 1 من منصة Meeting/White Label: أعضاء وأدوار + branding + دومينات مخصصة + موارد API معزولة بـ tenant_id.
    Route::get('/tenants', [TenantController::class, 'index'])->middleware('throttle:120,1');
    Route::post('/tenants', [TenantController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/tenants/launch-options', [TenantController::class, 'launchOptions'])->middleware('throttle:60,1');
    Route::post('/tenants/launch', [TenantController::class, 'launch'])->middleware('throttle:3,60');
    Route::get('/tenants/launch/active', [TenantController::class, 'activeLaunch'])->middleware('throttle:120,1');
    Route::get('/tenants/launch/{uuid}', [TenantController::class, 'launchStatus'])->whereUuid('uuid')->middleware('throttle:240,1');
    Route::prefix('/tenants/{id}')->where(['id' => '[0-9]+'])->group(function () {
        Route::get('/', [TenantController::class, 'show'])->middleware('throttle:120,1');
        Route::put('/', [TenantController::class, 'update'])->middleware('throttle:30,1');
        Route::delete('/', [TenantController::class, 'destroy'])->middleware('throttle:5,1');
        Route::get('/branding', [TenantController::class, 'branding'])->middleware('throttle:120,1');
        Route::put('/branding', [TenantController::class, 'saveBranding'])->middleware('throttle:30,1');
        Route::get('/members', [TenantController::class, 'members'])->middleware('throttle:120,1');
        Route::get('/roles', [TenantController::class, 'roles'])->middleware('throttle:120,1');
        Route::post('/roles', [TenantController::class, 'createRole'])->middleware('throttle:30,1');
        Route::put('/roles/{roleId}', [TenantController::class, 'updateRole'])->whereNumber('roleId')->middleware('throttle:30,1');
        Route::delete('/roles/{roleId}', [TenantController::class, 'deleteRole'])->whereNumber('roleId')->middleware('throttle:20,1');
        Route::get('/teams', [TenantController::class, 'teams'])->middleware('throttle:120,1');
        Route::post('/teams', [TenantController::class, 'createTeam'])->middleware('throttle:30,1');
        Route::put('/teams/{teamId}', [TenantController::class, 'updateTeam'])->whereNumber('teamId')->middleware('throttle:30,1');
        Route::delete('/teams/{teamId}', [TenantController::class, 'deleteTeam'])->whereNumber('teamId')->middleware('throttle:20,1');
        Route::get('/activity', [TenantController::class, 'activity'])->middleware('throttle:60,1');
        Route::get('/features', [TenantController::class, 'features'])->middleware('throttle:120,1');
        Route::put('/features', [TenantController::class, 'setFeatures'])->middleware('throttle:20,1');
        Route::get('/platform-users', [TenantController::class, 'platformUsers'])->middleware('throttle:120,1');
        Route::post('/platform-users', [TenantController::class, 'createPlatformUser'])->middleware('throttle:30,1');
        Route::post('/platform-users/{userId}/password', [TenantController::class, 'resetPlatformUser'])->whereNumber('userId')->middleware('throttle:20,1');
        Route::post('/platform-users/{userId}/invite', [TenantController::class, 'invitePlatformUser'])->whereNumber('userId')->middleware('throttle:20,1');
        Route::get('/checklist', [TenantController::class, 'checklist'])->middleware('throttle:120,1');
        Route::put('/platform-users/{userId}/status', [TenantController::class, 'statusPlatformUser'])->whereNumber('userId')->middleware('throttle:30,1');
        Route::get('/sign-in', [TenantController::class, 'signIn'])->middleware('throttle:120,1');
        Route::put('/sign-in', [TenantController::class, 'saveSignIn'])->middleware('throttle:30,1');
        // الدخول والأمان: دومين الإيميل، IP allowlist، الجلسة، الباسورد، Magic link، Passkeys، SSO (OIDC)، سيندر الإيميل.
        Route::get('/security', [TenantController::class, 'security'])->middleware('throttle:120,1');
        Route::put('/security', [TenantController::class, 'saveSecurity'])->middleware('throttle:30,1');
        Route::post('/security/test-sso', [TenantController::class, 'testSso'])->middleware('throttle:10,1');
        Route::post('/security/test-saml', [TenantController::class, 'testSaml'])->middleware('throttle:10,1');
        Route::get('/role-labels', [TenantController::class, 'roleLabels'])->middleware('throttle:120,1');
        Route::put('/role-labels', [TenantController::class, 'saveRoleLabels'])->middleware('throttle:30,1');
        Route::post('/members', [TenantController::class, 'addMember'])->middleware('throttle:30,1');
        Route::put('/members/{userId}', [TenantController::class, 'setRole'])->whereNumber('userId')->middleware('throttle:30,1');
        Route::delete('/members/{userId}', [TenantController::class, 'removeMember'])->whereNumber('userId')->middleware('throttle:30,1');
        Route::get('/domains', [TenantController::class, 'domains'])->middleware('throttle:120,1');
        Route::post('/domains', [TenantController::class, 'addDomain'])->middleware('throttle:20,1');
        Route::post('/domains/{domainId}/verify', [TenantController::class, 'verifyDomain'])->whereNumber('domainId')->middleware('throttle:30,1');
        Route::post('/domains/{domainId}/primary', [TenantController::class, 'primaryDomain'])->whereNumber('domainId')->middleware('throttle:30,1');
        Route::delete('/domains/{domainId}', [TenantController::class, 'removeDomain'])->whereNumber('domainId')->middleware('throttle:20,1');
        // موقع العميل: قوالب + تخصيص + معاينة + نشر + الرابط الجاهز (/w/<slug>).
        Route::get('/site', [TenantSiteController::class, 'overview'])->middleware('throttle:120,1');
        Route::put('/site/draft', [TenantSiteController::class, 'saveDraft'])->middleware('throttle:90,1');
        Route::post('/site/apply-template', [TenantSiteController::class, 'applyTemplate'])->middleware('throttle:30,1');
        Route::post('/site/discard', [TenantSiteController::class, 'discard'])->middleware('throttle:30,1');
        Route::post('/site/publish', [TenantSiteController::class, 'publish'])->middleware('throttle:20,1');
        Route::get('/site/versions', [TenantSiteController::class, 'versions'])->middleware('throttle:60,1');
        Route::post('/site/versions/{versionId}/restore', [TenantSiteController::class, 'restoreVersion'])->whereNumber('versionId')->middleware('throttle:30,1');
        Route::post('/site/preview-token', [TenantSiteController::class, 'previewToken'])->middleware('throttle:20,1');
        Route::get('/site/templates', [TenantSiteController::class, 'templates'])->middleware('throttle:60,1');
        Route::post('/site/templates', [TenantSiteController::class, 'saveTemplate'])->middleware('throttle:20,1');
        Route::delete('/site/templates/{templateId}', [TenantSiteController::class, 'deleteTemplate'])->whereNumber('templateId')->middleware('throttle:20,1');
        Route::put('/slug', [TenantSiteController::class, 'setSlug'])->middleware('throttle:10,1');
        Route::get('/apps', [TenantController::class, 'apps'])->middleware('throttle:120,1');
        Route::post('/apps', [TenantController::class, 'createApp'])->middleware('throttle:20,1');
        Route::delete('/apps/{appId}', [TenantController::class, 'deleteApp'])->whereNumber('appId')->middleware('throttle:20,1');
        Route::get('/participants', [TenantController::class, 'participants'])->middleware('throttle:120,1');
        Route::post('/participants', [TenantController::class, 'saveParticipant'])->middleware('throttle:120,1');
        Route::delete('/participants/{externalId}', [TenantController::class, 'deleteParticipant'])->where('externalId', '[A-Za-z0-9._:@\-]+')->middleware('throttle:60,1');
        Route::get('/embed-origins', [TenantController::class, 'embedOrigins'])->middleware('throttle:120,1');
        Route::post('/embed-origins', [TenantController::class, 'addEmbedOrigin'])->middleware('throttle:30,1');
        Route::delete('/embed-origins/{originId}', [TenantController::class, 'removeEmbedOrigin'])->whereNumber('originId')->middleware('throttle:30,1');
        Route::get('/usage', [TenantController::class, 'usage'])->middleware('throttle:60,1');
        Route::get('/logs', [TenantController::class, 'logs'])->middleware('throttle:120,1');
        Route::get('/keys', [TenantController::class, 'keys'])->middleware('throttle:120,1');
        Route::post('/keys', [TenantController::class, 'createKey'])->middleware('throttle:20,1');
        Route::delete('/keys/{keyId}', [TenantController::class, 'revokeKey'])->whereNumber('keyId')->middleware('throttle:30,1');
        Route::get('/webhooks', [TenantController::class, 'webhooks'])->middleware('throttle:120,1');
        Route::post('/webhooks', [TenantController::class, 'createWebhook'])->middleware('throttle:20,1');
        Route::delete('/webhooks/{hookId}', [TenantController::class, 'deleteWebhook'])->whereNumber('hookId')->middleware('throttle:20,1');
    });
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
    Route::post('/apps/{id}/review', [DevAdminController::class, 'reviewApp'])->whereNumber('id')->middleware('throttle:30,1');
    Route::get('/review/impact', [DevAdminController::class, 'reviewImpact'])->middleware('throttle:60,1');
    Route::post('/apps/{id}/suspend', [DevAdminController::class, 'suspend'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/apps/{id}/resume', [DevAdminController::class, 'resume'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('/keys', [DevAdminController::class, 'keys'])->middleware('throttle:120,1');
    Route::delete('/keys/{id}', [DevAdminController::class, 'revokeKey'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('/webhooks', [DevAdminController::class, 'webhooks'])->middleware('throttle:120,1');
    Route::post('/webhooks/{id}/active', [DevAdminController::class, 'setWebhookActive'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('/logs', [DevAdminController::class, 'logs'])->middleware('throttle:120,1');

    Route::get('/tenants', [TenantAdminController::class, 'index'])->middleware('throttle:120,1');
    Route::put('/tenants/{id}', [TenantAdminController::class, 'update'])->whereNumber('id')->middleware('throttle:30,1');
});

// 4) Developer API العام: Bearer uipk_… / uipo_… (+ scope). الـ middleware dev.auth بيتحط على كل route (مرة واحدة) والـ rate limit جواه لكل مفتاح/توكن.
Route::prefix('dev/v1')->group(function () {
    // التوثيق: OpenAPI 3.1 عام (من غير اعتماد) — مولّد من الـ routes الفعلية تحت. DevOpenApi بيتجاهل أي route من غير dev.auth فمش بيوثّق نفسه.
    Route::get('/openapi.json', [DevDocsController::class, 'openapi'])->middleware('throttle:60,1');
    // شرح التحقق من توقيع الـ Webhook + test vector + كود جاهز للمستقبِل (عام، من غير اعتماد، بنفس منطق openapi.json).
    Route::get('/docs/webhook-verification', [DevDocsController::class, 'webhookVerification'])->middleware('throttle:60,1');
    // تنزيل الـ SDKs جاهزة (zip لكل لغة) من Developer Center — عام، من غير اعتماد، مش جوه الـ OpenAPI (من غير dev.auth).
    // White Label: هوية الجهة اللي بتخدم الدومين + نقطة `ask` بتاعة Caddy on-demand TLS. عامة، من غير dev.auth، مش جوه الـ OpenAPI.
    Route::get('/tenant/branding', [TenantPublicController::class, 'branding'])->middleware('throttle:120,1');
    Route::get('/tenant/site', [TenantPublicController::class, 'site'])->middleware('throttle:120,1');
    Route::get('/tenant/domain-check', [TenantPublicController::class, 'domainCheck'])->middleware('throttle:300,1');
    // Embed SDK (الخطوة 2): صفحة الـ iframe + تبديل التوكن + جلسة الـ iframe + الـ loader. عامة، من غير dev.auth، مش جوه الـ OpenAPI.
    Route::get('/embed/loader.js', [EmbedPublicController::class, 'loader'])->middleware('throttle:120,1');
    Route::get('/embed/frame', [EmbedPublicController::class, 'frame'])->middleware('throttle:120,1');
    Route::post('/embed/exchange', [EmbedPublicController::class, 'exchange'])->middleware('throttle:60,1');
    Route::get('/embed/session', [EmbedPublicController::class, 'session'])->middleware('throttle:120,1');
    Route::delete('/embed/session', [EmbedPublicController::class, 'endSession'])->middleware('throttle:60,1');
    Route::get('/sdks/{lang}.zip', [DevSdkDownloadController::class, 'download'])->whereIn('lang', ['php', 'js', 'python'])->middleware('throttle:30,1');

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

    // المشاركين الخارجيين (المستخدم النهائي عند العميل) + توكن دخول الـ Embed — مفتاح tenant بس، live بس.
    Route::get('/participants', [DevParticipantController::class, 'index'])->middleware('dev.auth:participants.manage');
    Route::get('/participants/{external_id}', [DevParticipantController::class, 'show'])->where('external_id', '[A-Za-z0-9._:@\-]+')->middleware('dev.auth:participants.manage');
    Route::put('/participants/{external_id}', [DevParticipantController::class, 'upsert'])->where('external_id', '[A-Za-z0-9._:@\-]+')->middleware('dev.auth:participants.manage');
    Route::delete('/participants/{external_id}', [DevParticipantController::class, 'destroy'])->where('external_id', '[A-Za-z0-9._:@\-]+')->middleware('dev.auth:participants.manage');
    Route::post('/embed/sessions', [DevParticipantController::class, 'embedSession'])->middleware('dev.auth:embed.sessions');

    Route::post('/sandbox/simulate-incoming', [DevApiController::class, 'sandboxSimulate'])->middleware('dev.auth:mail.read');
    Route::post('/sandbox/reset', [DevApiController::class, 'sandboxReset'])->middleware('dev.auth:mail.read');
});
