<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إعدادات سطح المكتب ("جهازي") لكل مستخدم على السيرفر — بتتابع الطالب على أي جهاز (موبايل / لابتوب).
 *  - prefs/icons/mode/locale: الخلفية الجاهزة، اللون، حجم الأيقونات، أماكن الأيقونات، فاتح/داكن، اللغة (JSON نصي).
 *  - wallpaper: صورة الخلفية المخصصة (data URL مضغوط) — منفصلة عشان طلب الإعدادات يفضل خفيف.
 *  - lock_hash: باسورد شاشة القفل (bcrypt) — اختياري. NULL = القفل بدون باسورد (اضغط للفتح).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('desktop_settings')) {
            return;
        }
        Schema::create('desktop_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->text('prefs')->nullable();
            $table->text('icons')->nullable();
            $table->string('mode', 8)->nullable();
            $table->string('locale', 8)->nullable();
            $table->unsignedBigInteger('updated_ms')->default(0);   // وقت آخر تعديل (ms) — الأحدث بيكسب
            $table->mediumText('wallpaper')->nullable();
            $table->string('wp_hash', 40)->nullable();
            $table->string('lock_hash', 255)->nullable();
            $table->dateTime('lock_changed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_settings');
    }
};
