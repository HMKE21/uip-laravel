<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->enum('scope', ['global', 'user'])->default('global');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('key', 150);
            $table->text('value')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['scope', 'user_id', 'key'], 'uq_settings_scope_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
