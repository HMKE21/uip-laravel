<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpis', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('unit', 30)->nullable();
            $table->decimal('current_value', 18, 2)->default(0);
            $table->decimal('target_value', 18, 2)->default(0);
            $table->enum('direction', ['higher_better', 'lower_better'])->default('higher_better');
            $table->boolean('alert_enabled')->default(0);
            $table->decimal('alert_threshold', 18, 2)->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->index(['category'], 'idx_kpis_category');
            $table->index(['status'], 'idx_kpis_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpis');
    }
};
