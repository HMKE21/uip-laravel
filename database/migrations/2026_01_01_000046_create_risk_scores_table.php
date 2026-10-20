<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_scores', function (Blueprint $table) {
            $table->id();
            $table->enum('entity_type', ['user', 'ip']);
            $table->string('entity_ref', 120);
            $table->unsignedTinyInteger('score')->default(0);
            $table->enum('level', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->json('factors')->nullable();
            $table->dateTime('calculated_at')->useCurrent();
            $table->unique(['entity_type', 'entity_ref'], 'uniq_entity');
            $table->index(['level'], 'idx_risk_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_scores');
    }
};
