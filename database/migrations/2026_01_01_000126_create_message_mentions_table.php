<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_mentions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('mentioned_user_id');
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('message_id')->references('id')->on('messages')->onDelete('cascade');
            $table->foreign('mentioned_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['message_id', 'mentioned_user_id'], 'uq_message_mentions');
            $table->index(['mentioned_user_id'], 'idx_message_mentions_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_mentions');
    }
};
