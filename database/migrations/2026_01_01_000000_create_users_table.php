<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('full_name', 150);
            $table->string('email', 190);
            $table->string('phone', 30)->nullable();
            $table->string('password_hash', 255);
            $table->string('avatar_path', 255)->nullable();
            $table->enum('preferred_language', ['ar', 'en'])->default('ar');
            $table->enum('theme_preference', ['light', 'dark'])->default('light');
            $table->enum('status', ['active', 'pending', 'suspended', 'banned'])->default('pending');
            $table->dateTime('email_verified_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->boolean('two_factor_enabled')->default(0);
            $table->string('remember_token', 100)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('deleted_at')->nullable();
            $table->string('two_factor_secret', 64)->nullable();
            $table->dateTime('two_factor_confirmed_at')->nullable();
            $table->json('two_factor_recovery_codes')->nullable();
            $table->string('pending_email', 190)->nullable();
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->boolean('lock_permanent')->default(0);
            $table->string('lock_reason', 255)->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->unsignedBigInteger('unlocked_by')->nullable();
            $table->dateTime('unlocked_at')->nullable();
            $table->dateTime('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(0);
            $table->dateTime('mfa_grace_started_at')->nullable();
            $table->unique(['uuid']);
            $table->unique(['email']);
            $table->index(['email'], 'idx_users_email');
            $table->index(['status'], 'idx_users_status');
            $table->index(['locked_until'], 'idx_users_locked_until');
            $table->index(['lock_permanent'], 'idx_users_lock_permanent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
