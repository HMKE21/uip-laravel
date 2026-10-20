<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('selector', 24);
            $table->string('token_hash', 64);
            $table->string('device_label', 120)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('last_used_at')->useCurrent();
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['selector']);
            $table->index(['user_id', 'revoked_at'], 'idx_trusted_devices_user');
            $table->index(['expires_at'], 'idx_trusted_devices_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
