<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('saved_query_id')->nullable();
            $table->text('sql_text');
            $table->enum('status', ['success', 'error']);
            $table->string('error_message', 500)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedInteger('execution_time_ms')->nullable();
            $table->dateTime('executed_at')->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('saved_query_id')->references('id')->on('saved_queries')->onDelete('set null');
            $table->index(['user_id', 'executed_at'], 'idx_query_history_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_history');
    }
};
