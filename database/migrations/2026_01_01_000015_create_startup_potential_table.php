<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('startup_potential', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->decimal('potential_score', 5, 2)->default(0);
            $table->string('market_size_estimate', 100)->nullable();
            $table->text('competitive_edge')->nullable();
            $table->json('risk_factors')->nullable();
            $table->boolean('is_demo_data')->default(1);
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->unique(['project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('startup_potential');
    }
};
