<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حذف أي حساب (كل الأدوار) بقى قابل للاسترجاع: بنسجّل الحالة قبل الحذف
 * (active/pending/suspended) عشان الاسترجاع يرجّعه زي ما كان بدل ما يخليه active دايمًا.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status_before_delete', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('status_before_delete');
        });
    }
};
