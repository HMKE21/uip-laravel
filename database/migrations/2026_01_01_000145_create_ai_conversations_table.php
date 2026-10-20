<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('portal', 40)->default('general');
            $table->string('title', 200)->default('New chat');
            $table->boolean('is_pinned')->default(0);
            $table->boolean('is_archived')->default(0);
            $table->string('share_token', 64)->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->dateTime('last_message_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['share_token'], 'uq_ai_conversations_share_token');
            $table->index(['user_id', 'is_archived', 'is_pinned', 'last_message_at'], 'idx_ai_conversations_user');
            $table->index(['portal'], 'idx_ai_conversations_portal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
