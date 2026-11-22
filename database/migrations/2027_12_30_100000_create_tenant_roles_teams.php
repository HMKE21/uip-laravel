<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أدوار مخصصة لكل مساحة (مصفوفة صلاحيات: لكل قسم none | view | edit) + أقسام/فرق (مبيعات، دعم…).
 * العضو بيفضل ليه role أساسي في tenant_members (للتوافق)، و custom_role_id لو المالك ادّاله دور مخصص.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tenant_custom_roles')) {
            Schema::create('tenant_custom_roles', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('name_en', 60)->nullable();
                $t->string('name_ar', 60)->nullable();
                $t->string('description', 160)->nullable();
                $t->text('permissions');                       // JSON: { section: none|view|edit }
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index('tenant_id', 'idx_tcr_tenant');
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }
        if (Schema::hasTable('tenant_members') && !Schema::hasColumn('tenant_members', 'custom_role_id')) {
            Schema::table('tenant_members', function (Blueprint $t) {
                $t->unsignedBigInteger('custom_role_id')->nullable();
                $t->index('custom_role_id', 'idx_tm_custom_role');
            });
        }
        if (!Schema::hasTable('tenant_teams')) {
            Schema::create('tenant_teams', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('name_en', 60)->nullable();
                $t->string('name_ar', 60)->nullable();
                $t->string('color', 7)->nullable();
                $t->timestamps();
                $t->index('tenant_id', 'idx_tteam_tenant');
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }
        if (!Schema::hasTable('tenant_team_members')) {
            Schema::create('tenant_team_members', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('team_id');
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('user_id');
                $t->boolean('is_lead')->default(false);
                $t->timestamps();
                $t->unique(['team_id', 'user_id'], 'uq_team_user');
                $t->index(['tenant_id', 'user_id'], 'idx_ttm_tenant_user');
                $t->foreign('team_id')->references('id')->on('tenant_teams')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_team_members');
        Schema::dropIfExists('tenant_teams');
        if (Schema::hasTable('tenant_members') && Schema::hasColumn('tenant_members', 'custom_role_id')) {
            Schema::table('tenant_members', function (Blueprint $t) {
                $t->dropIndex('idx_tm_custom_role');
                $t->dropColumn('custom_role_id');
            });
        }
        Schema::dropIfExists('tenant_custom_roles');
    }
};
