<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** تصميم صفحة دخول كل منصة + مزوّدو الدخول (جوجل/مايكروسوفت بالـ client id بتاعها). على قاعدة المنصة الأم. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tenant_auth_settings')) {
            Schema::create('tenant_auth_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->unique();
                $t->string('template', 24)->default('split');
                $t->text('config')->nullable();
                $t->timestamps();
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_auth_settings');
    }
};
