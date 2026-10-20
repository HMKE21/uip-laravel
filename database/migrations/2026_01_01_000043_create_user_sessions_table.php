<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('session_token', 128);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_label', 120)->nullable();
            $table->string('location_label', 120)->nullable();
            $table->boolean('is_active')->default(1);
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('last_activity_at')->useCurrent();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('expires_at')->nullable();
            $table->string('revoke_reason', 40)->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('revoked_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['session_token']);
            $table->index(['user_id', 'is_active'], 'idx_sessions_user_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
