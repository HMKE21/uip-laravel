<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أجهزة الموبايل المسجّلة لاستقبال Push (Firebase Cloud Messaging).
 * التوكن واحد لكل تثبيت للتطبيق؛ لو نفس الجهاز سجّل دخول بحساب تاني بيتنقل الصف لليوزر الجديد
 * (التوكن unique)، فمفيش إشعارات حساب قديم بتوصل لجهاز حساب جديد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('push_devices')) {
            return;
        }
        Schema::create('push_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('token', 512)->unique();
            $table->string('device_id', 64)->nullable()->index();   // X-Device-Id (ثابت لكل تثبيت)
            $table->string('platform', 16)->default('android');
            $table->string('locale', 5)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
