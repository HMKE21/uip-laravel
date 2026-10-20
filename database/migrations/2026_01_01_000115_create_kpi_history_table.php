<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kpi_id');
            $table->decimal('value', 18, 2);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->dateTime('recorded_at')->useCurrent();
            $table->foreign('kpi_id')->references('id')->on('kpis')->onDelete('cascade');
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['kpi_id', 'recorded_at'], 'idx_kpi_history_kpi');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_history');
    }
};
