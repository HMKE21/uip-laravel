<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedTinyInteger('option_index');
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('message_id')->references('id')->on('messages')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['message_id', 'user_id', 'option_index'], 'uq_message_poll_votes_single_choice');
            $table->index(['message_id'], 'idx_message_poll_votes_message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_poll_votes');
    }
};
