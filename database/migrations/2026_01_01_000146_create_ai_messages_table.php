<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->mediumText('content');
            $table->enum('status', ['complete', 'streaming', 'error'])->default('complete');
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->boolean('is_bookmarked')->default(0);
            $table->text('reactions_json')->nullable();
            $table->unsignedBigInteger('parent_message_id')->nullable();
            $table->mediumText('context_snapshot')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->dateTime('edited_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->enum('answer_source', ['ai', 'predefined'])->nullable();
            $table->unsignedBigInteger('faq_intent_id')->nullable();
            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->onDelete('cascade');
            $table->index(['conversation_id', 'created_at'], 'idx_ai_messages_conversation');
            $table->index(['faq_intent_id'], 'idx_ai_messages_faq_intent');
            $table->fullText(['content'], 'ft_ai_messages_content');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
