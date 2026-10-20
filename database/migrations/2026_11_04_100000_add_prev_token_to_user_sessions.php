<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "دخول دائم" (زي فيسبوك): الـ refresh token بيتبدّل مع كل استخدام. لو الرد ضاع في الشبكة (موبايل/واي فاي بيقطع)
 * العميل بيرجع يبعت التوكن القديم — ومن غير تسامح السيرفر كان بيعتبره سرقة ويقفل كل جلسات الحساب.
 * بنخزّن هاش التوكن السابق ووقت التبديل على الجلسة نفسها، فالتوكن القديم يتقبّل دقيقة واحدة بعد تبديله
 * (ويرجع نفس الجلسة، مش جلسة جديدة) وبعدها يرجع الاكتشاف العادي لإعادة الاستخدام.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('user_sessions', 'prev_token_hash')) {
            Schema::table('user_sessions', function (Blueprint $table) {
                $table->string('prev_token_hash', 64)->nullable();
                $table->dateTime('rotated_at')->nullable();
                $table->index('prev_token_hash', 'idx_sessions_prev_token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_sessions', 'prev_token_hash')) {
            Schema::table('user_sessions', function (Blueprint $table) {
                $table->dropIndex('idx_sessions_prev_token');
                $table->dropColumn(['prev_token_hash', 'rotated_at']);
            });
        }
    }
};
