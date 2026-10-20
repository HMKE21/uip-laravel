<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('reviewer_id');
            $table->enum('stage', ['university_review', 'admin_review']);
            $table->enum('decision', ['pending', 'approved', 'rejected', 'changes_requested'])->default('pending');
            $table->text('comments')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->boolean('ai_output_acknowledged')->default(0);
            $table->json('ai_output_snapshot')->nullable();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('reviewer_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['stage', 'decision'], 'idx_approvals_stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_approvals');
    }
};
