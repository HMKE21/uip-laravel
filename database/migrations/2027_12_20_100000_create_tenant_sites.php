<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * White-label — موقع العميل (قوالب + تخصيص + معاينة + نشر):
 *  - tenant_sites: مسودة (draft) ومنشور (published) لكل عميل + توكن المعاينة. المسودة مش بتظهر للمستخدم النهائي إلا بالتوكن.
 *  - tenant_site_versions: آخر نسخ منشورة (rollback).
 *  - tenant_site_templates: قوالب العميل نفسه (Save as template). القوالب الجاهزة جوه الكود (TenantSiteTemplates).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_sites')) {
            Schema::create('tenant_sites', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->unique();
                $t->string('template_key', 60)->nullable();            // آخر قالب اتطبّق (للعرض بس)
                $t->json('draft')->nullable();
                $t->json('published')->nullable();
                $t->unsignedInteger('version')->default(0);            // بيزيد مع كل نشر
                $t->dateTime('draft_updated_at')->nullable();
                $t->dateTime('published_at')->nullable();
                $t->unsignedBigInteger('published_by')->nullable();
                $t->string('preview_token', 48)->nullable();
                $t->timestamps();
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('tenant_site_versions')) {
            Schema::create('tenant_site_versions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedInteger('version');
                $t->json('settings');
                $t->unsignedBigInteger('published_by')->nullable();
                $t->dateTime('published_at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'version'], 'idx_site_versions_tenant');
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('tenant_site_templates')) {
            Schema::create('tenant_site_templates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('name', 60);
                $t->json('settings');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index('tenant_id', 'idx_site_templates_tenant');
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_site_templates');
        Schema::dropIfExists('tenant_site_versions');
        Schema::dropIfExists('tenant_sites');
    }
};
