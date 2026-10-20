<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * عدّاد زوار المنصة (الفوتر العام). جدولين:
 * - site_visitors: زائر فريد لكل صف (hash لمعرّف عشوائي بيتولّد في متصفح
 *   الزائر — مفيش IP ولا بيانات شخصية بتتخزّن)، مع عدد زياراته.
 * - site_counters: عدّادات مجمّعة (total_visits / unique_visitors) عشان
 *   القراءة العامة تبقى O(1) من غير COUNT(*) على كل طلب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visitors', function (Blueprint $table) {
            $table->id();
            $table->char('visitor_hash', 64)->unique();
            $table->unsignedInteger('visits_count')->default(1);
            $table->dateTime('first_visit_at')->useCurrent();
            $table->dateTime('last_visit_at')->useCurrent();
        });

        Schema::create('site_counters', function (Blueprint $table) {
            $table->string('name', 50)->primary();
            $table->unsignedBigInteger('value')->default(0);
            $table->dateTime('updated_at')->nullable();
        });

        DB::table('site_counters')->insert([
            ['name' => 'total_visits',     'value' => 0, 'updated_at' => now()],
            ['name' => 'unique_visitors',  'value' => 0, 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_counters');
        Schema::dropIfExists('site_visitors');
    }
};
