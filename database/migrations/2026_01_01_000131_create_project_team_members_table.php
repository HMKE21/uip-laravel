<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_team_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('invited_email', 190)->nullable();
            $table->enum('role', ['supervisor', 'collaborator', 'student_member', 'principal_investigator', 'professor', 'external_collaborator', 'teaching_assistant'])->default('student_member');
            $table->enum('status', ['pending', 'accepted', 'rejected', 'removed'])->default('pending');
            $table->unsignedBigInteger('invited_by');
            $table->dateTime('invited_at')->useCurrent();
            $table->dateTime('responded_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->string('member_name', 190)->nullable();
            $table->unsignedTinyInteger('academic_year')->nullable();
            $table->string('student_number', 50)->nullable();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('invited_by')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['project_id', 'invited_email'], 'uq_project_team_project_email');
            $table->index(['project_id'], 'idx_project_team_project');
            $table->index(['user_id', 'status'], 'idx_project_team_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_team_members');
    }
};
