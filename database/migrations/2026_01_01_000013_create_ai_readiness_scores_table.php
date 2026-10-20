<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_readiness_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->decimal('overall_score', 5, 2)->default(0);
            $table->decimal('technical_score', 5, 2)->nullable();
            $table->decimal('market_score', 5, 2)->nullable();
            $table->decimal('innovation_score', 5, 2)->nullable();
            $table->decimal('presentation_score', 5, 2)->nullable();
            $table->dateTime('computed_at')->nullable();
            $table->boolean('is_demo_data')->default(1);
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->unique(['project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_readiness_scores');
    }
};
