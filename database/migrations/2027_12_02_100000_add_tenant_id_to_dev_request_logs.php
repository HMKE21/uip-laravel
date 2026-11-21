<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UIPE Platform — الخطوة 1 (تكملة): عزل سجل الطلبات.
 * tenant_id على dev_request_logs: طلبات مفاتيح/تطبيقات الـ Workspace وعمليات إدارته (audit) بتتختم بيه، فالسجل والاستهلاك الشخصي
 * (tenant_id = null) مبيتخلطوش بسجل الـ Workspace والعكس. الصفوف القديمة بتفضل null = شخصية. مفيش بيانات بتتغيّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dev_request_logs') && !Schema::hasColumn('dev_request_logs', 'tenant_id')) {
            Schema::table('dev_request_logs', function (Blueprint $t) {
                $t->unsignedBigInteger('tenant_id')->nullable();
                $t->index(['tenant_id', 'id'], 'idx_dev_log_tenant');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dev_request_logs') && Schema::hasColumn('dev_request_logs', 'tenant_id')) {
            Schema::table('dev_request_logs', function (Blueprint $t) {
                $t->dropIndex('idx_dev_log_tenant');
                $t->dropColumn('tenant_id');
            });
        }
    }
};
