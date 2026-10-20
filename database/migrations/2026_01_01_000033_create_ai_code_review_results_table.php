<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_code_review_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('repository_id')->nullable();
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');
            $table->unsignedInteger('issues_found')->default(0);
            $table->text('summary')->nullable();
            $table->json('raw_result')->nullable();
            $table->boolean('is_demo_data')->default(1);
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedTinyInteger('overall_score')->nullable();
            $table->unsignedTinyInteger('security_score')->nullable();
            $table->unsignedTinyInteger('performance_score')->nullable();
            $table->unsignedTinyInteger('maintainability_score')->nullable();
            $table->unsignedTinyInteger('architecture_score')->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('previous_review_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->boolean('score_overridden')->default(0);
            $table->text('override_reason')->nullable();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('repository_id')->references('id')->on('github_repositories')->onDelete('set null');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['previous_review_id'], 'idx_ai_code_review_previous');
            $table->index(['reviewed_by'], 'idx_ai_code_review_reviewed_by');
            $table->index(['project_id', 'version'], 'idx_ai_code_review_project_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_code_review_results');
    }
};
