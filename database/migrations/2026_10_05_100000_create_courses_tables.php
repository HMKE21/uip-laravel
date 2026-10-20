<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courses (المواد / المقررات): created by the university, a faculty or a doctor; students pick the
 * ones they take; doctors see exactly who is in each course. Exams can point at a course.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('courses')) {
            Schema::create('courses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('university_id')->index();
                $table->unsignedBigInteger('faculty_id')->nullable()->index();   // null = open to the whole university
                $table->unsignedBigInteger('department_id')->nullable()->index();
                $table->string('code', 40);
                $table->string('name_en', 200);
                $table->string('name_ar', 200)->nullable();
                $table->text('description')->nullable();
                $table->unsignedTinyInteger('credit_hours')->nullable();
                $table->unsignedTinyInteger('academic_year')->nullable();        // 1..8, optional hint for students
                $table->unsignedTinyInteger('semester')->nullable();             // 1 | 2
                $table->string('status', 20)->default('active');                 // active | archived
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
                $table->unique(['university_id', 'code']);
            });
        }

        if (!Schema::hasTable('course_staff')) {
            Schema::create('course_staff', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('academic_staff_id')->index();
                $table->timestamps();
                $table->unique(['course_id', 'academic_staff_id']);
            });
        }

        if (!Schema::hasTable('course_enrollments')) {
            Schema::create('course_enrollments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->string('source', 20)->default('self');                   // self | staff
                $table->timestamp('enrolled_at')->nullable();
                $table->timestamps();
                $table->unique(['course_id', 'student_id']);
            });
        }

        if (Schema::hasTable('exams') && !Schema::hasColumn('exams', 'course_id')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->unsignedBigInteger('course_id')->nullable()->after('subject')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('exams') && Schema::hasColumn('exams', 'course_id')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->dropColumn('course_id');
            });
        }
        Schema::dropIfExists('course_enrollments');
        Schema::dropIfExists('course_staff');
        Schema::dropIfExists('courses');
    }
};
