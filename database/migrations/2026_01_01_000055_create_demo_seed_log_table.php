<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_seed_log', function (Blueprint $table) {
            $table->id();
            $table->char('batch_id', 36);
            $table->string('table_name', 64);
            $table->unsignedBigInteger('record_id');
            $table->dateTime('created_at')->useCurrent();
            $table->index(['batch_id', 'table_name'], 'idx_demo_seed_log_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_seed_log');
    }
};
