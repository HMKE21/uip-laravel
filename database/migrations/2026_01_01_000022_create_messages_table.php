<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('sender_id');
            $table->text('body');
            $table->string('attachment_path', 255)->nullable();
            $table->boolean('is_edited')->default(0);
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('parent_message_id')->nullable();
            $table->unsignedBigInteger('forwarded_from_id')->nullable();
            $table->string('message_type', 20)->default('text');
            $table->json('metadata')->nullable();
            $table->boolean('is_pinned')->default(0);
            $table->dateTime('edited_at')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->dateTime('deleted_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->string('deleted_reason', 255)->nullable();
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['conversation_id', 'created_at'], 'idx_messages_conversation');
            $table->index(['parent_message_id'], 'idx_messages_parent');
            $table->index(['conversation_id', 'is_pinned'], 'idx_messages_pinned');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
