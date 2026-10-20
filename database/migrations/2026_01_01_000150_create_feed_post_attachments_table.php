<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_post_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->enum('kind', ['image', 'video', 'pdf', 'file', 'link']);
            $table->string('file_path', 500)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('external_url', 1000)->nullable();
            $table->string('link_title', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('post_id')->references('id')->on('feed_posts')->onDelete('cascade');
            $table->index(['post_id'], 'idx_feed_post_attachments_post');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_post_attachments');
    }
};
