<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('joined_at')->useCurrent();
            $table->dateTime('last_read_at')->nullable();
            $table->string('role', 20)->default('member');
            $table->boolean('is_favorite')->default(0);
            $table->boolean('is_pinned')->default(0);
            $table->boolean('is_muted')->default(0);
            $table->boolean('is_archived')->default(0);
            $table->string('category', 50)->nullable();
            $table->dateTime('last_typing_at')->nullable();
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->primary(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
    }
};
