<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ربط حساب المنصة بحساب جوجل (sub ثابت لا يتغير حتى لو الإيميل اتغير). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_social_accounts')) {
            return;
        }
        Schema::create('user_social_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 30);
            $table->string('provider_user_id', 191);
            $table->string('email', 190)->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['provider', 'provider_user_id']);
            $table->index(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_accounts');
    }
};
