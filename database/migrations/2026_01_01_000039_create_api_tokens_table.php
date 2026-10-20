<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->char('token_hash', 64);
            $table->unsignedBigInteger('created_by');
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['token_hash'], 'uq_api_tokens_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
