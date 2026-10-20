<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_analysis_consents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->string('policy_version', 20);
            $table->string('ip_address', 64)->nullable();
            $table->dateTime('consented_at')->useCurrent();
            $table->dateTime('revoked_at')->nullable();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['project_id'], 'uq_ai_analysis_consents_project');
            $table->index(['user_id'], 'idx_ai_analysis_consents_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analysis_consents');
    }
};
