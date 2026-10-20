<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graduation_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('university_id');
            $table->enum('status', ['graduated', 'revoked'])->default('graduated');
            $table->date('graduation_date');
            $table->decimal('final_gpa', 3, 2)->nullable();
            $table->string('degree_title_ar', 200)->nullable();
            $table->string('degree_title_en', 200)->nullable();
            $table->string('faculty_name_ar', 200)->nullable();
            $table->string('faculty_name_en', 200)->nullable();
            $table->string('department_name_ar', 200)->nullable();
            $table->string('department_name_en', 200)->nullable();
            $table->string('program_name_ar', 200)->nullable();
            $table->string('program_name_en', 200)->nullable();
            $table->string('certificate_number', 60);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('approved_by');
            $table->dateTime('approved_at');
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('student_id')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('university_id')->references('id')->on('universities')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('revoked_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['student_id'], 'uq_graduation_records_student');
            $table->unique(['certificate_number'], 'uq_graduation_records_certificate_number');
            $table->index(['university_id', 'status'], 'idx_graduation_records_university');
            $table->index(['status'], 'idx_graduation_records_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graduation_records');
    }
};
