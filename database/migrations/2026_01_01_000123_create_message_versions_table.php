<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->text('body');
            $table->dateTime('edited_at')->useCurrent();
            $table->foreign('message_id')->references('id')->on('messages')->onDelete('cascade');
            $table->index(['message_id', 'edited_at'], 'idx_message_versions_message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_versions');
    }
};
