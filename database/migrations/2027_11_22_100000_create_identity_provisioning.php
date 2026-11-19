<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UIPE Mail — المرحلة 5: الهوية والـ Provisioning.
 *  - login_events      : سجل الدخول (نجاح/فشل، الطريقة، الجهاز، الدولة، جهاز جديد).
 *  - passkeys          : مفاتيح المرور (WebAuthn) لكل مستخدم.
 *  - scim_tokens       : توكنز SCIM لكل tenant (بيتخزّن الـ hash بس).
 *  - scim_identities   : ربط externalId بتاع الـ IdP بمستخدم المنصة.
 *  - provisioning_runs : سجل عمليات الاستيراد (CSV) والـ SCIM.
 *  - users.tokens_valid_after : أي access token صدر قبل التاريخ ده بيبطل (خروج من كل الأجهزة).
 *  - mail_tenants    : auto_provision / suspend_on_expiry / graduate_grace_days.
 *  - mail_accounts   : membership_expires_at + suspended_by (عشان الاستئناف التلقائي ميفتحش صندوق الأدمن وقّفه).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('login_events')) {
            Schema::create('login_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('identifier', 190)->nullable();
                $t->boolean('success')->default(false);
                $t->string('method', 16)->default('password');      // password|google|microsoft|keycloak|github|2fa|passkey
                $t->string('failure_reason', 40)->nullable();
                $t->string('ip_address', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->string('device_label', 120)->nullable();
                $t->char('device_hash', 64)->nullable();
                $t->string('location_label', 120)->nullable();
                $t->boolean('is_new_device')->default(false);
                $t->dateTime('created_at')->useCurrent();
                $t->index(['user_id', 'id'], 'idx_login_events_user');
                $t->index(['ip_address', 'created_at'], 'idx_login_events_ip');
                $t->index(['created_at'], 'idx_login_events_created');
            });
        }

        if (!Schema::hasTable('passkeys')) {
            Schema::create('passkeys', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('credential_id', 400);                  // base64url
                $t->text('public_key');                            // PEM
                $t->integer('alg');                                // -7 (ES256) | -257 (RS256)
                $t->unsignedBigInteger('sign_count')->default(0);
                $t->string('transports', 120)->nullable();
                $t->string('name', 80)->default('Passkey');
                $t->boolean('backed_up')->default(false);
                $t->dateTime('last_used_at')->nullable();
                $t->dateTime('revoked_at')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->unique(['credential_id'], 'uq_passkeys_cred');
                $t->index(['user_id', 'revoked_at'], 'idx_passkeys_user');
            });
        }

        if (!Schema::hasTable('scim_tokens')) {
            Schema::create('scim_tokens', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('name', 80);
                $t->char('token_hash', 64);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->dateTime('last_used_at')->nullable();
                $t->dateTime('expires_at')->nullable();
                $t->dateTime('revoked_at')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->unique(['token_hash'], 'uq_scim_tokens_hash');
                $t->index(['tenant_id', 'revoked_at'], 'idx_scim_tokens_tenant');
            });
        }

        if (!Schema::hasTable('scim_identities')) {
            Schema::create('scim_identities', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('user_id');
                $t->string('external_id', 190)->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->unique(['tenant_id', 'user_id'], 'uq_scim_ident_user');
                $t->unique(['tenant_id', 'external_id'], 'uq_scim_ident_ext');
            });
        }

        if (!Schema::hasTable('provisioning_runs')) {
            Schema::create('provisioning_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable();
                $t->unsignedBigInteger('university_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->string('kind', 16);                            // csv | scim | auto | sweep
                $t->string('member_type', 12)->nullable();
                $t->boolean('dry_run')->default(false);
                $t->unsignedInteger('total')->default(0);
                $t->unsignedInteger('created')->default(0);
                $t->unsignedInteger('failed')->default(0);
                $t->json('errors')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->index(['tenant_id', 'id'], 'idx_prov_runs_tenant');
            });
        }

        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'tokens_valid_after')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dateTime('tokens_valid_after')->nullable();
            });
        }

        if (Schema::hasTable('mail_tenants') && !Schema::hasColumn('mail_tenants', 'auto_provision')) {
            Schema::table('mail_tenants', function (Blueprint $t) {
                $t->boolean('auto_provision')->default(true);
                $t->boolean('suspend_on_expiry')->default(true);
                $t->unsignedSmallInteger('graduate_grace_days')->default(180);
            });
        }

        if (Schema::hasTable('mail_accounts') && !Schema::hasColumn('mail_accounts', 'membership_expires_at')) {
            Schema::table('mail_accounts', function (Blueprint $t) {
                $t->dateTime('membership_expires_at')->nullable();
                $t->string('suspended_by', 12)->nullable();        // membership | null (أدمن/يدوي)
            });
        }
    }

    public function down(): void
    {
        foreach (['provisioning_runs', 'scim_identities', 'scim_tokens', 'passkeys', 'login_events'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
        if (Schema::hasColumn('users', 'tokens_valid_after')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('tokens_valid_after'));
        }
        if (Schema::hasColumn('mail_tenants', 'auto_provision')) {
            Schema::table('mail_tenants', fn (Blueprint $t) => $t->dropColumn(['auto_provision', 'suspend_on_expiry', 'graduate_grace_days']));
        }
        if (Schema::hasColumn('mail_accounts', 'membership_expires_at')) {
            Schema::table('mail_accounts', fn (Blueprint $t) => $t->dropColumn(['membership_expires_at', 'suspended_by']));
        }
    }
};
