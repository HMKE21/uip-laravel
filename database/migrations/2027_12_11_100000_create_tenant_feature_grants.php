<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * White-label tenancy — الخطوة 4: ميزات كل عميل.
 * الافتراضي: مفيش صف = الميزة مقفولة. العميل بيطلب (pending) وأدمن المنصة بيقرر (enabled | rejected | disabled).
 * مركزي بس: بيتخطى نفسه على قواعد العملاء (tenant:migrate بيشغّل كل الـ migrations هناك كمان).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getName() === 'tenant' || Schema::hasTable('tenant_feature_grants') || !Schema::hasTable('tenants')) {
            return;
        }
        Schema::create('tenant_feature_grants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('feature', 40);
            $t->string('status', 10)->default('pending');                 // pending | enabled | rejected | disabled
            $t->unsignedBigInteger('requested_by')->nullable();           // id المستخدم جوه قاعدة العميل
            $t->string('request_note', 255)->nullable();
            $t->dateTime('requested_at')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();             // id أدمن المنصة
            $t->string('decision_note', 255)->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'feature'], 'uq_tenant_feature');
            $t->index('status', 'idx_tfg_status');
            $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getName() === 'tenant') {
            return;
        }
        Schema::dropIfExists('tenant_feature_grants');
    }
};
