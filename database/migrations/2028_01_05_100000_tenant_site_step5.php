<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * موقع العميل — الخطوة 5:
 *  - tenant_domains: تفعيل تلقائي (next_check_at/check_count) + حالة شهادة SSL (ssl_status/ssl_checked_at/ssl_expires_at/ssl_error).
 *  - tenant_site_messages: رسائل نموذج التواصل في موقع العميل (صندوق وارد بسيط للمالك).
 * الصفحات والمدونة والـ SEO والخطوط جوه JSON المسودة/المنشور الموجودين — مفيش جداول ليهم.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_domains')) {
            Schema::table('tenant_domains', function (Blueprint $t) {
                if (!Schema::hasColumn('tenant_domains', 'ssl_status')) {
                    $t->string('ssl_status', 10)->default('none');            // none | pending | active | failed
                }
                if (!Schema::hasColumn('tenant_domains', 'ssl_checked_at')) {
                    $t->dateTime('ssl_checked_at')->nullable();
                }
                if (!Schema::hasColumn('tenant_domains', 'ssl_expires_at')) {
                    $t->dateTime('ssl_expires_at')->nullable();
                }
                if (!Schema::hasColumn('tenant_domains', 'ssl_error')) {
                    $t->string('ssl_error', 190)->nullable();
                }
                if (!Schema::hasColumn('tenant_domains', 'next_check_at')) {
                    $t->dateTime('next_check_at')->nullable();
                    $t->index('next_check_at', 'idx_tenant_dom_next_check');
                }
                if (!Schema::hasColumn('tenant_domains', 'check_count')) {
                    $t->unsignedInteger('check_count')->default(0);
                }
            });
        }

        if (!Schema::hasTable('tenant_site_messages')) {
            Schema::create('tenant_site_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('name', 80);
                $t->string('email', 150);
                $t->string('subject', 120)->nullable();
                $t->text('message');
                $t->string('status', 10)->default('new');                    // new | read | archived
                $t->string('fingerprint', 64)->nullable();                   // hash(email+message) لمنع التكرار
                $t->string('ip_hash', 64)->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'status', 'id'], 'idx_site_msgs_tenant');
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_site_messages');
        if (Schema::hasTable('tenant_domains')) {
            Schema::table('tenant_domains', function (Blueprint $t) {
                foreach (['ssl_status', 'ssl_checked_at', 'ssl_expires_at', 'ssl_error', 'check_count'] as $c) {
                    if (Schema::hasColumn('tenant_domains', $c)) {
                        $t->dropColumn($c);
                    }
                }
                if (Schema::hasColumn('tenant_domains', 'next_check_at')) {
                    $t->dropIndex('idx_tenant_dom_next_check');
                    $t->dropColumn('next_check_at');
                }
            });
        }
    }
};
