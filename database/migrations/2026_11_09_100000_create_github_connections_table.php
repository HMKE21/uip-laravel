<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Connect GitHub" (OAuth): one row per user. The access token is stored
 * ENCRYPTED (Crypt::encryptString, keyed by APP_KEY) — never plain text.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('github_connections')) {
            return;
        }

        Schema::create('github_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('github_user_id');
            $table->string('github_login', 100);
            $table->string('avatar_url', 500)->nullable();
            $table->text('access_token');          // encrypted
            $table->string('scope', 255)->nullable();
            $table->dateTime('connected_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->index('github_user_id', 'idx_github_connections_gh_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_connections');
    }
};
