<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('uploader_id');
            $table->string('kind', 20)->default('file');
            $table->string('stored_path', 255);
            $table->string('thumbnail_path', 255)->nullable();
            $table->string('original_name', 255);
            $table->string('mime_type', 127)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('waveform_json')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->boolean('is_encrypted')->default(0);
            $table->foreign('message_id')->references('id')->on('messages')->onDelete('cascade');
            $table->foreign('uploader_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['message_id'], 'idx_message_attachments_message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
