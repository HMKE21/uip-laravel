<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @username (زي تليجرام) + خصوصية البحث بالإيميل.
 *  users.username                       اختياري، فريد، حروف صغيرة (a-z 0-9 _)، التحقق في UsernameService
 *  users.username_changed_at            آخر تغيير (للـ cooldown)
 *  user_network_settings.email_searchable  1 = الناس تقدر تلاقيني بكتابة إيميلي كامل (الافتراضي = نعم، زي السلوك الحالي)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('username', 32)->nullable()->after('full_name');
                $table->dateTime('username_changed_at')->nullable();
                $table->unique('username', 'uq_users_username');
            });
        }
        if (Schema::hasTable('user_network_settings') && !Schema::hasColumn('user_network_settings', 'email_searchable')) {
            Schema::table('user_network_settings', function (Blueprint $table) {
                $table->boolean('email_searchable')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('uq_users_username');
                $table->dropColumn(['username', 'username_changed_at']);
            });
        }
        if (Schema::hasColumn('user_network_settings', 'email_searchable')) {
            Schema::table('user_network_settings', function (Blueprint $table) {
                $table->dropColumn('email_searchable');
            });
        }
    }
};
