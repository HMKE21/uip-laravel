<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UIPE Platform — الخطوة 1: Tenants مستقلة (شركات / جامعات / مطورين) فوق الـ Developer API.
 *  - tenants: الجهة نفسها (حالة + خطة + features + حدود). university_id اختياري: ربط بجامعة موجودة (مش بيغيّر mail_tenants).
 *  - tenant_members: مين يدير الـ tenant وبأي دور (owner | admin | developer | viewer).
 *  - tenant_branding: الاسم والشعار والألوان وروابط الدعم (وإخفاء هوية UIPE لو الـ tenant عنده white_label).
 *  - tenant_domains: النطاقات المخصصة. pending → verified (DNS TXT) → active (CNAME/توجيه سليم).
 *  - tenant_id على dev_apps / dev_api_keys / dev_webhooks: null = زي ما كانت (شخصية)، مفيش تغيير في اللي موجود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $t) {
                $t->id();
                $t->string('public_id', 32)->unique();                       // tnt_xxxxxxxxxxxxxxxx — ده اللي بيظهر بره المنصة
                $t->string('name', 100);
                $t->string('slug', 60)->unique();
                $t->string('kind', 12)->default('company');                   // company | university | developer
                $t->unsignedBigInteger('university_id')->nullable()->unique();
                $t->unsignedBigInteger('owner_user_id');
                $t->string('status', 12)->default('active');                 // active | suspended
                $t->string('suspended_reason', 190)->nullable();
                $t->string('plan', 20)->default('free');
                $t->json('features')->nullable();                             // ["white_label","custom_domain"] — بيحطها أدمن المنصة بس
                $t->json('limits')->nullable();                               // يغلب الافتراضي في config/tenancy.php
                $t->timestamps();
                $t->index('owner_user_id', 'idx_tenants_owner');
                $t->index('status', 'idx_tenants_status');
            });
        }

        if (!Schema::hasTable('tenant_members')) {
            Schema::create('tenant_members', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('user_id');
                $t->string('role', 12)->default('developer');                 // owner | admin | developer | viewer
                $t->unsignedBigInteger('invited_by')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'user_id'], 'uq_tenant_member');
                $t->index('user_id', 'idx_tenant_member_user');
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('tenant_branding')) {
            Schema::create('tenant_branding', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->unique();
                $t->string('display_name', 80)->nullable();
                $t->string('logo_url', 300)->nullable();
                $t->string('favicon_url', 300)->nullable();
                $t->string('primary_color', 7)->nullable();                   // #RRGGBB
                $t->string('accent_color', 7)->nullable();
                $t->string('theme', 6)->default('auto');                      // light | dark | auto
                $t->string('default_locale', 5)->default('en');               // en | ar
                $t->string('support_email', 150)->nullable();
                $t->string('support_url', 300)->nullable();
                $t->string('privacy_url', 300)->nullable();
                $t->string('terms_url', 300)->nullable();
                $t->boolean('hide_platform_branding')->default(false);        // مسموح بس لو features فيها white_label
                $t->timestamps();
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('tenant_domains')) {
            Schema::create('tenant_domains', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('domain', 253)->unique();
                $t->boolean('is_primary')->default(false);
                $t->string('status', 10)->default('pending');                 // pending | verified | active | disabled
                $t->string('verification_token', 48);
                $t->dateTime('verified_at')->nullable();
                $t->dateTime('activated_at')->nullable();
                $t->dateTime('last_checked_at')->nullable();
                $t->string('last_error', 190)->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'is_primary'], 'idx_tenant_dom_tenant');
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }

        foreach (['dev_apps', 'dev_api_keys', 'dev_webhooks'] as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->unsignedBigInteger('tenant_id')->nullable();
                    $t->index('tenant_id', 'idx_' . $table . '_tenant');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['dev_apps', 'dev_api_keys', 'dev_webhooks'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex('idx_' . $table . '_tenant');
                    $t->dropColumn('tenant_id');
                });
            }
        }
        Schema::dropIfExists('tenant_domains');
        Schema::dropIfExists('tenant_branding');
        Schema::dropIfExists('tenant_members');
        Schema::dropIfExists('tenants');
    }
};
