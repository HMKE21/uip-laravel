<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التسجيل الأكاديمي:
 *  - course_prerequisites  : المتطلبات السابقة لكل مادة.
 *  - registration_periods  : فترات التسجيل (university | faculty) بتاريخ فتح وقفل ونوع (enroll | drop | both).
 *  - registration_policies : سياسة القواعد لكل مستوى (university | faculty) — null = يورّث من الأعلى ثم config/registration.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('course_prerequisites')) {
            Schema::create('course_prerequisites', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('prerequisite_course_id')->index();
                $table->timestamps();
                $table->unique(['course_id', 'prerequisite_course_id']);
            });
        }

        if (!Schema::hasTable('registration_periods')) {
            Schema::create('registration_periods', function (Blueprint $table) {
                $table->id();
                $table->string('scope_type', 12);                       // university | faculty
                $table->unsignedBigInteger('scope_id')->index();
                $table->string('name', 160);
                $table->string('kind', 8)->default('both');             // enroll | drop | both
                $table->dateTime('opens_at');
                $table->dateTime('closes_at');
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
                $table->index(['scope_type', 'scope_id', 'opens_at']);
            });
        }

        if (!Schema::hasTable('registration_policies')) {
            Schema::create('registration_policies', function (Blueprint $table) {
                $table->id();
                $table->string('scope_type', 12);                       // university | faculty
                $table->unsignedBigInteger('scope_id');
                $table->boolean('enforce_period')->nullable();
                $table->boolean('enforce_prerequisites')->nullable();
                $table->boolean('enforce_hours')->nullable();
                $table->boolean('enforce_conflicts')->nullable();
                $table->unsignedTinyInteger('max_hours')->nullable();
                $table->decimal('low_gpa_threshold', 3, 2)->nullable();
                $table->unsignedTinyInteger('low_gpa_max_hours')->nullable();
                $table->decimal('high_gpa_threshold', 3, 2)->nullable();
                $table->unsignedTinyInteger('high_gpa_max_hours')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->timestamps();
                $table->unique(['scope_type', 'scope_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_policies');
        Schema::dropIfExists('registration_periods');
        Schema::dropIfExists('course_prerequisites');
    }
};
