<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('innovation_statistics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('university_id')->nullable();
            $table->string('category', 100)->nullable();
            $table->unsignedInteger('total_projects')->default(0);
            $table->unsignedInteger('approved_projects')->default(0);
            $table->decimal('avg_readiness_score', 5, 2)->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('university_id')->references('id')->on('universities')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('innovation_statistics');
    }
};
