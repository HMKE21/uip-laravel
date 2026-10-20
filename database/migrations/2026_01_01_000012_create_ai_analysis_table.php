<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_analysis', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->enum('analysis_type', ['summary', 'classification', 'readiness_score', 'startup_potential', 'improvement_suggestions']);
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');
            $table->json('raw_result')->nullable();
            $table->string('model_used', 100)->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('completed_at')->nullable();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->index(['analysis_type', 'status'], 'idx_ai_analysis_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analysis');
    }
};
