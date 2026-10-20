<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trends', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 150);
            $table->decimal('trend_score', 6, 2)->default(0);
            $table->unsignedInteger('project_count')->default(0);
            $table->date('period_month');
            $table->dateTime('created_at')->useCurrent();
            $table->index(['period_month'], 'idx_trends_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trends');
    }
};
