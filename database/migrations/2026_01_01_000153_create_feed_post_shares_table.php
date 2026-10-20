<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_post_shares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('post_id')->references('id')->on('feed_posts')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['post_id'], 'idx_feed_post_shares_post');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_post_shares');
    }
};
