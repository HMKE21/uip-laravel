<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "الأمان والأجهزة" في إعدادات سطح المكتب:
 *  - desktop_settings.security: JSON بإعدادات الأمان الشخصية (حد الأجهزة، القفل التلقائي، السكون، الخروج التلقائي…).
 *  - user_sessions.remote_lock_at: أمر "اقفل الجهاز ده" من جهاز تاني — الجهاز المستهدف بيلقطه في أول pulse.
 * آمنة على أي داتابيز: بتضيف بس اللي ناقص.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('desktop_settings') && !Schema::hasColumn('desktop_settings', 'security')) {
            Schema::table('desktop_settings', function (Blueprint $table) {
                $table->text('security')->nullable();
            });
        }
        if (Schema::hasTable('user_sessions') && !Schema::hasColumn('user_sessions', 'remote_lock_at')) {
            Schema::table('user_sessions', function (Blueprint $table) {
                $table->dateTime('remote_lock_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('desktop_settings', 'security')) {
            Schema::table('desktop_settings', function (Blueprint $table) {
                $table->dropColumn('security');
            });
        }
        if (Schema::hasColumn('user_sessions', 'remote_lock_at')) {
            Schema::table('user_sessions', function (Blueprint $table) {
                $table->dropColumn('remote_lock_at');
            });
        }
    }
};
