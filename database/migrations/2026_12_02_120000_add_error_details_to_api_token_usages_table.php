<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تفاصيل الفشل في سجل استخدام التوكن: رسالة الخطأ + كود الخطأ (لو الـ API رجّع code) +
 * لو الطلب خلّص بـ 5xx: اسم الـ exception ومكانه في الكود. بتتسجّل لطلبات status >= 400 بس.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_token_usages', function (Blueprint $table) {
            if (!Schema::hasColumn('api_token_usages', 'error_code')) {
                $table->string('error_code', 60)->nullable()->after('outcome');
                $table->string('error_message', 500)->nullable()->after('error_code');
                $table->string('error_detail', 255)->nullable()->after('error_message');
                $table->index(['api_token_id', 'status'], 'idx_api_token_usages_token_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('api_token_usages', function (Blueprint $table) {
            $table->dropIndex('idx_api_token_usages_token_status');
            $table->dropColumn(['error_code', 'error_message', 'error_detail']);
        });
    }
};
