<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قوالب الامتحانات: exams.is_template = امتحان محفوظ للاستخدام المتكرر (بيفضل draft ومينفعش يتنشر للطلاب).
 * "استخدام القالب" = نسخ الامتحان (ExamCopyService::duplicate) — القالب نفسه عمره ما يتغير بالنسخ.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (!Schema::hasColumn('exams', 'is_template')) {
                $table->boolean('is_template')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (Schema::hasColumn('exams', 'is_template')) {
                $table->dropColumn('is_template');
            }
        });
    }
};
