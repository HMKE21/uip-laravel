<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مدة تثبيت الرسالة (زي واتساب + خيار "للأبد").
 * pinned_until = NULL مع is_pinned=1 يعني مثبّتة لحد ما حد يلغي التثبيت. بعد pinned_until
 * بتتعامل كأنها مش مثبّتة (الفلترة وقت القراءة، من غير cron).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dateTime('pinned_until')->nullable()->after('is_pinned');
            $table->dateTime('pinned_at')->nullable()->after('pinned_until');
            $table->unsignedBigInteger('pinned_by')->nullable()->after('pinned_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['pinned_until', 'pinned_at', 'pinned_by']);
        });
    }
};
