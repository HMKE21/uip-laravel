<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('export_type', 80);
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'custom'])->default('weekly');
            $table->enum('format', ['csv', 'pdf', 'xlsx'])->default('csv');
            $table->unsignedSmallInteger('custom_interval_days')->nullable();
            $table->string('recipient_email', 500);
            $table->boolean('is_active')->default(1);
            $table->dateTime('last_run_at')->nullable();
            $table->unsignedBigInteger('last_export_id')->nullable();
            $table->dateTime('next_run_at');
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('last_export_id')->references('id')->on('data_exports')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_schedules');
    }
};
