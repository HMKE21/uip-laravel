<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('uploader_id');
            $table->string('kind', 20);
            $table->string('stored_path', 255);
            $table->string('thumbnail_path', 255)->nullable();
            $table->string('original_name', 255);
            $table->string('mime_type', 120)->nullable();
            $table->string('extension', 20);
            $table->unsignedInteger('size_bytes');
            $table->mediumText('extracted_text')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('message_id')->references('id')->on('ai_messages')->onDelete('cascade');
            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->onDelete('cascade');
            $table->foreign('uploader_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['message_id'], 'idx_ai_attachments_message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_message_attachments');
    }
};
