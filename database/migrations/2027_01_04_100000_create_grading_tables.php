<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كشف الدرجات والـ GPA:
 *  - grade_scales            : نظام تقديرات لكل مستوى (global = الأدمن | university | faculty). الأقرب للمادة بيكسب.
 *  - grade_scale_bands       : شرائح النظام (حرف / نسبة دنيا / نقاط / رسوب؟).
 *  - course_grade_components : مكوّنات درجة المقرر غير الامتحانات (تكليف / حضور / درجة يدوية) + وزنها.
 *  - course_grade_component_scores : الدرجات اليدوية (للمكوّن من نوع manual).
 *  - course_final_grades     : النتيجة النهائية لكل طالب في المقرر (مسودة ← معروضة للاعتماد ← منشورة) + تفاصيل الحساب.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('grade_scales')) {
            Schema::create('grade_scales', function (Blueprint $table) {
                $table->id();
                $table->string('scope_type', 12);                       // global | university | faculty
                $table->unsignedBigInteger('scope_id')->default(0);     // 0 للـ global
                $table->string('name', 120)->nullable();
                $table->boolean('deprivation_fails')->default(true);
                $table->string('deprivation_letter', 8)->default('DN');
                $table->boolean('require_approval')->default(false);
                $table->unsignedTinyInteger('gpa_decimals')->default(2);
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->timestamps();
                $table->unique(['scope_type', 'scope_id']);
            });
        }

        if (!Schema::hasTable('grade_scale_bands')) {
            Schema::create('grade_scale_bands', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('scale_id')->index();
                $table->string('letter', 8);
                $table->decimal('min_percent', 5, 2);
                $table->decimal('points', 4, 2);
                $table->boolean('is_fail')->default(false);
                $table->unsignedSmallInteger('sort')->default(0);
            });
        }

        if (!Schema::hasTable('course_grade_components')) {
            Schema::create('course_grade_components', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->index();
                $table->string('kind', 12);                             // assignment | attendance | manual
                $table->unsignedBigInteger('ref_id')->nullable();       // assignment id لو kind=assignment
                $table->string('title', 200);
                $table->decimal('weight', 6, 2);
                $table->decimal('max_score', 8, 2)->nullable();         // للـ manual
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('course_grade_component_scores')) {
            Schema::create('course_grade_component_scores', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('component_id');
                $table->unsignedBigInteger('student_id');
                $table->decimal('score', 8, 2)->nullable();
                $table->unsignedBigInteger('entered_by_user_id')->nullable();
                $table->timestamps();
                $table->unique(['component_id', 'student_id']);
            });
        }

        if (!Schema::hasTable('course_final_grades')) {
            Schema::create('course_final_grades', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->decimal('points_earned', 7, 2)->default(0);
                $table->decimal('points_total', 7, 2)->default(0);
                $table->decimal('percentage', 5, 2)->default(0);
                $table->string('letter', 8)->nullable();
                $table->decimal('grade_points', 4, 2)->nullable();
                $table->boolean('is_fail')->default(false);
                $table->boolean('deprived')->default(false);
                $table->boolean('overridden')->default(false);
                $table->string('override_reason', 500)->nullable();
                $table->string('status', 12)->default('draft');         // draft | submitted | published
                $table->json('breakdown')->nullable();
                $table->dateTime('calculated_at')->nullable();
                $table->dateTime('published_at')->nullable();
                $table->unsignedBigInteger('published_by_user_id')->nullable();
                $table->timestamps();
                $table->unique(['course_id', 'student_id']);
                $table->index(['student_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_final_grades');
        Schema::dropIfExists('course_grade_component_scores');
        Schema::dropIfExists('course_grade_components');
        Schema::dropIfExists('grade_scale_bands');
        Schema::dropIfExists('grade_scales');
    }
};
