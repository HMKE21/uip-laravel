<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_insight_reports', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['completed', 'failed'])->default('completed');
            $table->longText('result_json')->nullable();
            $table->longText('data_snapshot_json')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->string('model_used', 100)->nullable();
            $table->unsignedBigInteger('generated_by');
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('generated_by')->references('id')->on('users')->onDelete('cascade');
            $table->index(['created_at'], 'idx_ai_insight_reports_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insight_reports');
    }
};
