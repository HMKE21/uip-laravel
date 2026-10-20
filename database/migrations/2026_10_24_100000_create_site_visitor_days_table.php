<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * زائر فريد لكل يوم — بيغذّي قسم "الزوار الحقيقيين" في داشبورد الأدمن.
 * (visitor_hash, visit_date) unique: الريفريش في نفس اليوم مبيضيفش صف.
 * الـ backfill بيستنتج الأيام المعروفة من first/last_visit_at في site_visitors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visitor_days', function (Blueprint $table) {
            $table->char('visitor_hash', 64);
            $table->date('visit_date');
            $table->primary(['visitor_hash', 'visit_date']);
            $table->index('visit_date');
        });

        foreach (['first_visit_at', 'last_visit_at'] as $col) {
            DB::table('site_visitors')->orderBy('id')->chunk(500, function ($rows) use ($col) {
                DB::table('site_visitor_days')->insertOrIgnore(
                    $rows->map(fn ($r) => [
                        'visitor_hash' => $r->visitor_hash,
                        'visit_date'   => substr((string) $r->{$col}, 0, 10),
                    ])->all()
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visitor_days');
    }
};
