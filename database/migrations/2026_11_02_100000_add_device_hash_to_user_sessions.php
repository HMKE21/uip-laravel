<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بصمة المتصفح (sha256 لكوكي uip_did) على كل جلسة، عشان "جلسة واحدة لكل حساب" تفرّق بين
 * جهاز تاني فعلًا وبين نفس المتصفح اللي رجع يدخل (نوم الجهاز / قطع الشبكة / مسح الدخول محليًا)
 * والجلسة القديمة بتاعته لسه "حية" على السيرفر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('user_sessions', 'device_hash')) {
            Schema::table('user_sessions', function (Blueprint $table) {
                $table->string('device_hash', 64)->nullable()->after('device_label');
                $table->index(['user_id', 'device_hash'], 'idx_sessions_user_device');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_sessions', 'device_hash')) {
            Schema::table('user_sessions', function (Blueprint $table) {
                $table->dropIndex('idx_sessions_user_device');
                $table->dropColumn('device_hash');
            });
        }
    }
};
