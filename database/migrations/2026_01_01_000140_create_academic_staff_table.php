<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('university_id');
            $table->unsignedBigInteger('faculty_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('academic_rank_id')->nullable();
            $table->string('staff_number', 50)->nullable();
            $table->text('password_encrypted')->nullable();
            $table->text('bio')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->enum('invitation_status', ['pending', 'accepted', 'expired'])->nullable();
            $table->dateTime('invited_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('university_id')->references('id')->on('universities')->onDelete('cascade');
            $table->foreign('faculty_id')->references('id')->on('faculties')->onDelete('set null');
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
            $table->foreign('academic_rank_id')->references('id')->on('academic_ranks')->onDelete('set null');
            $table->unique(['user_id']);
            $table->index(['university_id'], 'idx_academic_staff_university');
            $table->index(['faculty_id'], 'idx_academic_staff_faculty');
            $table->index(['department_id'], 'idx_academic_staff_department');
            $table->index(['status'], 'idx_academic_staff_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_staff');
    }
};
