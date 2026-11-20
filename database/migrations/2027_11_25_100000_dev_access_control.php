<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Developer Center — تحكم كامل في المطورين (الأدوار / الإيميلات / الصلاحيات / كل مطوّر لوحده).
 *  - dev_tenant_policies : أعمدة جديدة (الأدوار المسموحة، وضع الوصول open/closed، الموافقة المسبقة، sandbox فقط، حدود المفاتيح/الـ webhooks/الـ rate، الأحداث المسموحة).
 *    الصف university_id = 0 هو سياسة المنصة (سقف أعلى فوق كل الجامعات).
 *  - dev_access_rules    : قواعد سماح/منع بالإيميل (بالظبط أو نمط *) أو الدومين أو الدور، وممكن تحدد scopes وحدود لكل قاعدة.
 *  - dev_user_access     : تحكم لكل مطوّر: سماح/حظر/طلب وصول، scopes وحدود خاصة بيه، وتاريخ انتهاء.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dev_tenant_policies')) {
            Schema::table('dev_tenant_policies', function (Blueprint $t) {
                if (!Schema::hasColumn('dev_tenant_policies', 'allowed_roles')) {
                    $t->json('allowed_roles')->nullable();                           // null = كل الأدوار
                }
                if (!Schema::hasColumn('dev_tenant_policies', 'default_access')) {
                    $t->string('default_access', 8)->default('open');                // open | closed (closed = بس اللي ليهم قاعدة سماح أو سماح يدوي)
                }
                if (!Schema::hasColumn('dev_tenant_policies', 'require_approval')) {
                    $t->boolean('require_approval')->default(false);                 // لازم موافقة الإدارة قبل ما العضو يبدأ
                }
                if (!Schema::hasColumn('dev_tenant_policies', 'sandbox_only')) {
                    $t->boolean('sandbox_only')->default(false);                     // مفاتيح Sandbox بس
                }
                if (!Schema::hasColumn('dev_tenant_policies', 'max_keys_per_user')) {
                    $t->unsignedSmallInteger('max_keys_per_user')->nullable();
                }
                if (!Schema::hasColumn('dev_tenant_policies', 'max_webhooks_per_user')) {
                    $t->unsignedSmallInteger('max_webhooks_per_user')->nullable();
                }
                if (!Schema::hasColumn('dev_tenant_policies', 'rate_limit_per_min')) {
                    $t->unsignedInteger('rate_limit_per_min')->nullable();           // سقف الطلبات في الدقيقة لكل مفتاح/توكن
                }
                if (!Schema::hasColumn('dev_tenant_policies', 'allowed_events')) {
                    $t->json('allowed_events')->nullable();                          // null = كل أحداث الـ Webhooks
                }
                if (!Schema::hasColumn('dev_tenant_policies', 'updated_by')) {
                    $t->unsignedBigInteger('updated_by')->nullable();
                }
            });
        }

        if (!Schema::hasTable('dev_access_rules')) {
            Schema::create('dev_access_rules', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('university_id')->default(0);                 // 0 = قاعدة على مستوى المنصة
                $t->string('kind', 8);                                               // email | domain | role
                $t->string('pattern', 190);                                          // lowercase: a@b.com | cs-*@x.edu.eg | x.edu.eg | *.edu.eg | doctor
                $t->string('effect', 5)->default('allow');                           // allow | deny
                $t->json('scopes')->nullable();                                      // null = scopes السياسة
                $t->unsignedSmallInteger('max_apps')->nullable();
                $t->string('note', 200)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->dateTime('expires_at')->nullable();
                $t->timestamps();
                $t->unique(['university_id', 'kind', 'pattern'], 'uq_dev_rule');
                $t->index(['university_id', 'effect'], 'idx_dev_rule_uni');
            });
        }

        if (!Schema::hasTable('dev_user_access')) {
            Schema::create('dev_user_access', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->unique();
                $t->unsignedBigInteger('university_id')->nullable();
                $t->string('status', 10)->default('allowed');                        // allowed | blocked | pending | rejected
                $t->string('set_by', 10)->default('university');                     // university | admin | user
                $t->json('scopes')->nullable();                                      // null = من القاعدة/السياسة
                $t->unsignedSmallInteger('max_apps')->nullable();
                $t->unsignedSmallInteger('max_keys')->nullable();
                $t->unsignedSmallInteger('max_webhooks')->nullable();
                $t->boolean('webhooks_enabled')->nullable();
                $t->boolean('sandbox_only')->nullable();
                $t->unsignedInteger('rate_limit_per_min')->nullable();
                $t->string('reason', 200)->nullable();
                $t->string('request_note', 300)->nullable();
                $t->dateTime('expires_at')->nullable();
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->dateTime('decided_at')->nullable();
                $t->timestamps();
                $t->index(['university_id', 'status'], 'idx_dev_uacc_uni');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_user_access');
        Schema::dropIfExists('dev_access_rules');
        if (Schema::hasTable('dev_tenant_policies')) {
            foreach (['allowed_roles', 'default_access', 'require_approval', 'sandbox_only', 'max_keys_per_user', 'max_webhooks_per_user', 'rate_limit_per_min', 'allowed_events', 'updated_by'] as $c) {
                if (Schema::hasColumn('dev_tenant_policies', $c)) {
                    Schema::table('dev_tenant_policies', fn (Blueprint $t) => $t->dropColumn($c));
                }
            }
        }
    }
};
