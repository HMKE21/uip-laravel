<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_presence', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->dateTime('last_seen_at')->nullable();
            $table->unsignedBigInteger('is_typing_in')->nullable();
            $table->dateTime('typing_started_at')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('is_typing_in')->references('id')->on('conversations')->onDelete('set null');
            $table->primary(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_presence');
    }
};
