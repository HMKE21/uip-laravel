<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_records', function (Blueprint $table) {
            $table->id();
            $table->string('metric_key', 100);
            $table->decimal('metric_value', 18, 2)->default(0);
            $table->string('dimension', 100)->nullable();
            $table->date('recorded_for_date');
            $table->dateTime('created_at')->useCurrent();
            $table->index(['metric_key', 'recorded_for_date'], 'idx_analytics_key_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_records');
    }
};
