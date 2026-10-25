<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "الوضع الافتراضي" (UIP OS): الطالب بيختار يشتغل على المنصة كلها من جوّه سطح مكتب "جهازي".
 *  - desktop_settings.virtual_mode: 0/1 — اختيار الطالب. بيتخزن على السيرفر عشان يتابعه على أي جهاز.
 * إعادة تهيئة الجهاز (Reset this PC) مبتلمسهوش: ده تفضيل واجهة مش بيانات جهاز.
 * آمنة على أي داتابيز: بتضيف بس اللي ناقص.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('desktop_settings') && !Schema::hasColumn('desktop_settings', 'virtual_mode')) {
            Schema::table('desktop_settings', function (Blueprint $table) {
                $table->boolean('virtual_mode')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('desktop_settings') && Schema::hasColumn('desktop_settings', 'virtual_mode')) {
            Schema::table('desktop_settings', function (Blueprint $table) {
                $table->dropColumn('virtual_mode');
            });
        }
    }
};
