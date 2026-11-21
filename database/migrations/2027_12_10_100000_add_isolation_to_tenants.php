<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * White-label tenancy — الخطوة 1: أعمدة العزل على جدول tenants الموجود.
 *  - mode: shared (الـ workspaces القديمة بتاعة Developer API: من غير تبديل قاعدة) | isolated (قاعدة بيانات خاصة بالعميل).
 *  - db_name / db_status (none|provisioning|ready|failed) / provisioned_at.
 * مركزي بس: بيتخطى نفسه لما بيتشغّل على قاعدة عميل (connection اسمه "tenant").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getName() === 'tenant' || !Schema::hasTable('tenants')) {
            return;
        }
        Schema::table('tenants', function (Blueprint $t) {
            if (!Schema::hasColumn('tenants', 'mode')) {
                $t->string('mode', 10)->default('shared');
            }
            if (!Schema::hasColumn('tenants', 'db_name')) {
                $t->string('db_name', 64)->nullable()->unique();
            }
            if (!Schema::hasColumn('tenants', 'db_status')) {
                $t->string('db_status', 12)->default('none');
            }
            if (!Schema::hasColumn('tenants', 'provisioned_at')) {
                $t->dateTime('provisioned_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getName() === 'tenant' || !Schema::hasTable('tenants')) {
            return;
        }
        Schema::table('tenants', function (Blueprint $t) {
            foreach (['provisioned_at', 'db_status', 'db_name', 'mode'] as $c) {
                if (Schema::hasColumn('tenants', $c)) {
                    if ($c === 'db_name') {
                        $t->dropUnique(['db_name']);
                    }
                    $t->dropColumn($c);
                }
            }
        });
    }
};
