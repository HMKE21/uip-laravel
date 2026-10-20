<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * report_templates.data_source كان enum بالست datasets القديمة بس.
 * الكتالوج بقى فيه الامتحانات/الدكاترة/الطلاب/المقررات، فنحوّل العمود
 * لـ string عشان القيم الجديدة (وأي dataset يتضاف بعد كده في
 * config/data_explorer_datasets.php) تتخزن من غير migration جديدة كل مرة.
 * القيمة المسموحة بتتفحص في التطبيق ضد الكتالوج، مش في الـ schema.
 */
return new class extends Migration
{
    private const LEGACY = ['projects', 'users', 'ai_analysis', 'analytics_records', 'innovation_statistics', 'security_logs'];

    public function up(): void
    {
        if (!Schema::hasTable('report_templates') || !Schema::hasColumn('report_templates', 'data_source')) {
            return;
        }
        Schema::table('report_templates', function (Blueprint $table) {
            $table->string('data_source', 60)->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('report_templates') || !Schema::hasColumn('report_templates', 'data_source')) {
            return;
        }
        // الرجوع لـ enum مش آمن لو فيه قوالب بمصادر جديدة؛ نسيب العمود string في الحالة دي.
        $foreign = DB::table('report_templates')->whereNotIn('data_source', self::LEGACY)->exists();
        if ($foreign) {
            return;
        }
        Schema::table('report_templates', function (Blueprint $table) {
            $table->enum('data_source', self::LEGACY)->change();
        });
    }
};
