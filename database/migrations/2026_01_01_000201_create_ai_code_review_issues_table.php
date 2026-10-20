<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_code_review_issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('review_id');
            $table->enum('category', ['security', 'performance', 'maintainability', 'architecture', 'quality', 'general'])->default('general');
            $table->enum('severity', ['critical', 'high', 'medium', 'low', 'info'])->default('medium');
            $table->string('file_name', 500)->nullable();
            $table->unsignedInteger('line_number')->nullable();
            $table->text('description');
            $table->text('ai_recommendation')->nullable();
            $table->text('suggested_fix')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('review_id')->references('id')->on('ai_code_review_results')->onDelete('cascade');
            $table->index(['review_id'], 'idx_ai_code_review_issues_review');
            $table->index(['severity'], 'idx_ai_code_review_issues_severity');
            $table->index(['category'], 'idx_ai_code_review_issues_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_code_review_issues');
    }
};
