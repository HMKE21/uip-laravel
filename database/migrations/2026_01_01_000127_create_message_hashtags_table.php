<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_hashtags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->string('tag', 100);
            $table->foreign('message_id')->references('id')->on('messages')->onDelete('cascade');
            $table->index(['tag'], 'idx_message_hashtags_tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_hashtags');
    }
};
