<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('report_type', 80);
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'custom'])->default('weekly');
            $table->string('recipient_email', 190);
            $table->boolean('is_active')->default(1);
            $table->dateTime('last_run_at')->nullable();
            $table->unsignedBigInteger('last_report_id')->nullable();
            $table->dateTime('next_run_at');
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->enum('format', ['csv', 'pdf', 'xlsx'])->default('csv');
            $table->unsignedSmallInteger('custom_interval_days')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('last_report_id')->references('id')->on('reports')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
