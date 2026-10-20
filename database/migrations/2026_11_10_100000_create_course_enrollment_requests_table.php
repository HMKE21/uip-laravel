<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course enrol / drop REQUESTS (طلبات تسجيل / إلغاء تسجيل مادة).
 * A student no longer enrols or drops directly: a request (with an optional message + attachment)
 * goes to the university, the faculty, and the course's doctors / TAs. The FIRST of them to
 * approve applies the change immediately. A rejection by one party is recorded in
 * course_enrollment_request_decisions and only closes the request once every party rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('course_enrollment_requests')) {
            Schema::create('course_enrollment_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->string('type', 10);                                   // enroll | drop
                $table->string('status', 12)->default('pending')->index();    // pending | approved | rejected | cancelled
                $table->text('message')->nullable();
                $table->string('attachment_path', 255)->nullable();
                $table->string('attachment_name', 255)->nullable();
                $table->string('attachment_mime', 120)->nullable();
                $table->unsignedBigInteger('attachment_size')->nullable();
                $table->unsignedBigInteger('decided_by_user_id')->nullable();
                $table->string('decided_by_role', 20)->nullable();            // university | faculty | academic_staff
                $table->text('decision_note')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('course_enrollment_request_decisions')) {
            Schema::create('course_enrollment_request_decisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('request_id')->index();
                $table->unsignedBigInteger('user_id');
                $table->string('role', 20);
                $table->string('decision', 10);                               // approved | rejected
                $table->text('note')->nullable();
                $table->timestamps();
                $table->unique(['request_id', 'user_id'], 'cer_decisions_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_enrollment_request_decisions');
        Schema::dropIfExists('course_enrollment_requests');
    }
};
