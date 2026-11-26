<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تصاريح فتح "سجل النشاط" في Admin → My PC Control. السجل مقفول لحد ما الأدمن يدخل المفتاح السري
 * (SECRET_KEY_TO_PC_CONTROL_ACTIVITY_LOG في .env)؛ التصريح بيسمح بالعرض والحذف لمدة قصيرة وبينتهي لوحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drive_audit_grants')) {
            return;
        }
        Schema::create('drive_audit_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->index();
            $table->string('reason', 255);
            $table->string('ip', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_audit_grants');
    }
};
