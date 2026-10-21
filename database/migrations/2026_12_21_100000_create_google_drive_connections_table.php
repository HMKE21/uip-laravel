<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Drive backup link (OAuth authorization-code flow): one row per user.
 * The long-lived refresh token is stored ENCRYPTED (Crypt, keyed by APP_KEY) — never plain text.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('google_drive_connections')) {
            return;
        }

        Schema::create('google_drive_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('google_email', 190)->nullable();
            $table->text('refresh_token');          // encrypted
            $table->string('scope', 500)->nullable();
            $table->dateTime('connected_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_drive_connections');
    }
};
