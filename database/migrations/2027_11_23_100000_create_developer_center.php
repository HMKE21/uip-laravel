<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UIPE Mail — المرحلة 6: Developer Center + ربط البريد.
 *  - dev_apps / dev_api_keys / dev_oauth_grants / dev_oauth_codes / dev_oauth_tokens : التطبيقات والمفاتيح و OAuth 2.0
 *  - dev_webhooks / dev_webhook_deliveries : الـ Webhooks وسجل التسليم
 *  - dev_request_logs : سجل التكاملات (طلبات الـ API + OAuth + أحداث الأدمن)
 *  - dev_sandbox_messages : صندوق Sandbox (مفيش بريد حقيقي بيتلمس)
 *  - dev_tenant_policies : سياسة الجامعة للمطورين
 *  - mail_integration_links : سجل "اتشارك في شات / اتعمل له اجتماع / اتحفظ في Drive"
 *  - mail_notification_prefs : إعدادات إشعارات البريد لكل صندوق
 * كل الأسرار (client_secret / API key / tokens) بتتخزن hash بس (sha256) — الـ webhook secret متشفّر (Crypt).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dev_apps')) {
            Schema::create('dev_apps', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('owner_user_id');
                $t->unsignedBigInteger('university_id')->nullable();
                $t->string('name', 80);
                $t->string('description', 500)->nullable();
                $t->string('homepage_url', 300)->nullable();
                $t->string('client_id', 40)->unique();
                $t->char('client_secret_hash', 64)->nullable();
                $t->string('client_secret_hint', 12)->nullable();
                $t->string('client_type', 12)->default('confidential');     // confidential | public (PKCE بس)
                $t->json('redirect_uris')->nullable();
                $t->json('scopes')->nullable();                              // أقصى scopes يقدر يطلبها
                $t->string('status', 12)->default('active');                 // active | suspended
                $t->string('suspended_reason', 200)->nullable();
                $t->string('suspended_by', 12)->nullable();                  // owner | university | admin
                $t->unsignedInteger('rate_limit_per_min')->nullable();
                $t->dateTime('last_used_at')->nullable();
                $t->timestamps();
                $t->index(['owner_user_id'], 'idx_dev_apps_owner');
                $t->index(['university_id', 'status'], 'idx_dev_apps_uni');
            });
        }

        if (!Schema::hasTable('dev_oauth_grants')) {
            Schema::create('dev_oauth_grants', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('app_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('mail_account_id')->nullable();
                $t->json('scopes');
                $t->dateTime('revoked_at')->nullable();
                $t->dateTime('last_used_at')->nullable();
                $t->timestamps();
                $t->unique(['app_id', 'user_id'], 'uq_dev_grant');
                $t->index(['user_id', 'revoked_at'], 'idx_dev_grant_user');
            });
        }

        if (!Schema::hasTable('dev_api_keys')) {
            Schema::create('dev_api_keys', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('app_id')->nullable();
                $t->unsignedBigInteger('mail_account_id')->nullable();
                $t->string('name', 80);
                $t->string('mode', 8)->default('live');                      // live | sandbox
                $t->string('prefix', 20);                                    // للعرض بس: uipk_live_ab12…
                $t->char('key_hash', 64)->unique();
                $t->json('scopes');
                $t->json('ip_allowlist')->nullable();
                $t->dateTime('expires_at')->nullable();
                $t->dateTime('last_used_at')->nullable();
                $t->string('last_used_ip', 45)->nullable();
                $t->dateTime('revoked_at')->nullable();
                $t->timestamps();
                $t->index(['user_id', 'revoked_at'], 'idx_dev_keys_user');
                $t->index(['app_id'], 'idx_dev_keys_app');
            });
        }

        if (!Schema::hasTable('dev_oauth_codes')) {
            Schema::create('dev_oauth_codes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('grant_id');
                $t->unsignedBigInteger('app_id');
                $t->unsignedBigInteger('user_id');
                $t->char('code_hash', 64)->unique();
                $t->string('redirect_uri', 400);
                $t->json('scopes');
                $t->string('code_challenge', 128)->nullable();
                $t->dateTime('expires_at');
                $t->dateTime('used_at')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->index(['grant_id'], 'idx_dev_codes_grant');
            });
        }

        if (!Schema::hasTable('dev_oauth_tokens')) {
            Schema::create('dev_oauth_tokens', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('grant_id')->nullable();             // null = client_credentials
                $t->unsignedBigInteger('app_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('mail_account_id')->nullable();
                $t->char('access_hash', 64)->unique();
                $t->char('refresh_hash', 64)->nullable()->unique();
                $t->json('scopes');
                $t->dateTime('access_expires_at');
                $t->dateTime('refresh_expires_at')->nullable();
                $t->dateTime('revoked_at')->nullable();
                $t->dateTime('rotated_at')->nullable();                      // refresh استُخدم → اتبدّل (إعادة استخدامه = سرقة)
                $t->dateTime('last_used_at')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->index(['grant_id'], 'idx_dev_tok_grant');
                $t->index(['app_id', 'user_id'], 'idx_dev_tok_app_user');
            });
        }

        if (!Schema::hasTable('dev_webhooks')) {
            Schema::create('dev_webhooks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('app_id')->nullable();
                $t->unsignedBigInteger('mail_account_id')->nullable();      // null = كل صناديق المستخدم
                $t->string('name', 80)->nullable();
                $t->string('url', 500);
                $t->text('secret_enc');
                $t->json('events');
                $t->string('mode', 8)->default('live');                      // live | sandbox
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('failure_count')->default(0);
                $t->string('last_status', 12)->nullable();                   // success | failed
                $t->dateTime('last_delivery_at')->nullable();
                $t->dateTime('disabled_at')->nullable();
                $t->string('disabled_reason', 120)->nullable();
                $t->timestamps();
                $t->index(['user_id', 'mode', 'is_active'], 'idx_dev_wh_user');
            });
        }

        if (!Schema::hasTable('dev_webhook_deliveries')) {
            Schema::create('dev_webhook_deliveries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('webhook_id');
                $t->char('event_id', 36);
                $t->string('event', 40);
                $t->longText('payload');
                $t->string('status', 12)->default('pending');                // pending | success | retrying | failed
                $t->unsignedSmallInteger('attempts')->default(0);
                $t->dateTime('next_attempt_at')->nullable();
                $t->unsignedSmallInteger('response_code')->nullable();
                $t->unsignedInteger('response_ms')->nullable();
                $t->string('response_excerpt', 600)->nullable();
                $t->string('error', 200)->nullable();
                $t->dateTime('delivered_at')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->index(['webhook_id', 'id'], 'idx_dev_dlv_hook');
                $t->index(['status', 'next_attempt_at'], 'idx_dev_dlv_due');
                $t->index(['created_at'], 'idx_dev_dlv_created');
            });
        }

        if (!Schema::hasTable('dev_request_logs')) {
            Schema::create('dev_request_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('app_id')->nullable();
                $t->unsignedBigInteger('api_key_id')->nullable();
                $t->unsignedBigInteger('token_id')->nullable();
                $t->string('kind', 12)->default('api');                      // api | oauth | admin | webhook | sandbox
                $t->string('method', 8)->nullable();
                $t->string('path', 200)->nullable();
                $t->unsignedSmallInteger('status')->nullable();
                $t->unsignedInteger('duration_ms')->nullable();
                $t->string('scope', 40)->nullable();
                $t->string('error_code', 40)->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('note', 200)->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->index(['user_id', 'id'], 'idx_dev_log_user');
                $t->index(['app_id', 'id'], 'idx_dev_log_app');
                $t->index(['created_at'], 'idx_dev_log_created');
            });
        }

        if (!Schema::hasTable('dev_sandbox_messages')) {
            Schema::create('dev_sandbox_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('folder', 12)->default('inbox');                  // inbox | sent | drafts | trash
                $t->string('from_address', 190);
                $t->string('from_name', 120)->nullable();
                $t->json('to');
                $t->string('subject', 250)->default('');
                $t->mediumText('body_text')->nullable();
                $t->boolean('is_read')->default(false);
                $t->boolean('is_starred')->default(false);
                $t->dateTime('created_at')->useCurrent();
                $t->index(['user_id', 'folder', 'id'], 'idx_dev_sbx_user');
            });
        }

        if (!Schema::hasTable('dev_tenant_policies')) {
            Schema::create('dev_tenant_policies', function (Blueprint $t) {
                $t->unsignedBigInteger('university_id')->primary();
                $t->boolean('apps_enabled')->default(true);
                $t->boolean('webhooks_enabled')->default(true);
                $t->unsignedSmallInteger('max_apps_per_user')->nullable();
                $t->json('allowed_scopes')->nullable();                      // null = كل الـ scopes
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('mail_integration_links')) {
            Schema::create('mail_integration_links', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id');
                $t->unsignedBigInteger('message_id');
                $t->string('kind', 10);                                       // chat | meeting | drive | ai
                $t->string('target_type', 30)->nullable();
                $t->string('target_id', 64)->nullable();
                $t->string('label', 200)->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->index(['account_id', 'message_id'], 'idx_mail_ilink_msg');
            });
        }

        if (!Schema::hasTable('mail_notification_prefs')) {
            Schema::create('mail_notification_prefs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->unique();
                $t->string('mode', 8)->default('all');                        // all | vip | off
                $t->json('vip_senders')->nullable();
                $t->dateTime('mute_until')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['mail_notification_prefs', 'mail_integration_links', 'dev_tenant_policies', 'dev_sandbox_messages', 'dev_request_logs',
            'dev_webhook_deliveries', 'dev_webhooks', 'dev_oauth_tokens', 'dev_oauth_codes', 'dev_api_keys', 'dev_oauth_grants', 'dev_apps'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
