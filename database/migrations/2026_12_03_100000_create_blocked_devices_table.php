<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أجهزة محظورة يدويًا من /admin/mobile/{id} (تبويب الأجهزة).
 * الجهاز بيتعرّف بـ X-Device-Id (معرّف ثابت بيتولّد مرة على كل تثبيت للتطبيق). أي طلب API معاه المعرّف ده
 * بيتردّ 403 device_blocked لحد ما الأدمن يفك الحظر (بيتمسح الصف).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blocked_devices')) {
            return;
        }
        Schema::create('blocked_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 64)->unique();
            $table->string('label', 255)->nullable();     // اسم الجهاز وقت الحظر (Android 16 · MTN-NX1)
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('blocked_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_devices');
    }
};
