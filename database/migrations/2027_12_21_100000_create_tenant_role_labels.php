<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** أسماء مخصصة لأدوار كل مساحة (مثلاً employee => "مندوب"). الصلاحيات ثابتة؛ ده اسم العرض بس. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tenant_role_labels')) {
            Schema::create('tenant_role_labels', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('role', 12);
                $t->string('label_en', 40)->nullable();
                $t->string('label_ar', 40)->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'role'], 'uq_tenant_role_label');
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_role_labels');
    }
};
