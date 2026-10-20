<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('university_reverification_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('university_id');
            $table->enum('event', ['notified', 'auto_renewed', 'reverted_pending', 'failed']);
            $table->enum('method', ['automatic', 'manual'])->default('automatic');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('university_id')->references('id')->on('universities')->onDelete('cascade');
            $table->foreign('actor_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['university_id', 'created_at'], 'idx_reverif_log_university');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('university_reverification_log');
    }
};
