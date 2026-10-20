<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('university_id')->nullable();
            $table->string('student_number', 50)->nullable();
            $table->string('faculty', 150)->nullable();
            $table->string('department', 150)->nullable();
            $table->unsignedTinyInteger('academic_year')->nullable();
            $table->decimal('gpa', 3, 2)->nullable();
            $table->text('bio')->nullable();
            $table->json('skills')->nullable();
            $table->json('social_links')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unsignedTinyInteger('current_semester')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->enum('invitation_status', ['pending', 'accepted', 'expired'])->nullable();
            $table->dateTime('invited_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->unsignedBigInteger('faculty_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('program_id')->nullable();
            $table->date('study_start_date')->nullable();
            $table->date('expected_graduation_date')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id']);
            $table->index(['university_id'], 'idx_students_university');
            $table->index(['group_id'], 'idx_students_group');
            $table->index(['faculty_id'], 'idx_students_faculty_id');
            $table->index(['department_id'], 'idx_students_department_id');
            $table->index(['program_id'], 'idx_students_program_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
