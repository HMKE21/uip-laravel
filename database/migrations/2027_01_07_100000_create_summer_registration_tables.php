<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التسجيل الصيفي:
 *  - summer_terms          : فصل صيفي (للجامعة كلها أو لكلية) بفترة تسجيل وتواريخ وحد أقصى للساعات.
 *  - summer_offerings      : المواد المعروضة في الفصل + السعة (null = بدون حد).
 *  - summer_registrations  : طلبات الطلاب (pending | approved | rejected | cancelled). الموافقة بتسجّل الطالب في المادة (source = summer).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('summer_terms')) {
            Schema::create('summer_terms', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('university_id')->index();
                $table->unsignedBigInteger('faculty_id')->nullable()->index();   // null = الجامعة كلها
                $table->string('name', 160);
                $table->dateTime('opens_at');
                $table->dateTime('closes_at');
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->unsignedTinyInteger('max_hours')->default(9);
                $table->boolean('only_failed')->default(false);                  // المواد اللي الطالب راسب فيها بس
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('summer_offerings')) {
            Schema::create('summer_offerings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('term_id')->index();
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedSmallInteger('capacity')->nullable();
                $table->timestamps();
                $table->unique(['term_id', 'course_id']);
            });
        }

        if (!Schema::hasTable('summer_registrations')) {
            Schema::create('summer_registrations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('term_id')->index();
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->string('status', 12)->default('pending')->index();
                $table->text('message')->nullable();
                $table->text('decision_note')->nullable();
                $table->unsignedBigInteger('decided_by_user_id')->nullable();
                $table->string('decided_by_role', 12)->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
                $table->index(['term_id', 'course_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('summer_registrations');
        Schema::dropIfExists('summer_offerings');
        Schema::dropIfExists('summer_terms');
    }
};
