<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_user_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // Personalization (like ChatGPT "Custom instructions")
            $table->string('nickname', 60)->nullable();
            $table->text('about_you')->nullable();
            $table->text('response_instructions')->nullable();
            $table->string('tone', 20)->default('default');
            $table->string('response_length', 20)->default('default');
            // Per-user generation limits (null = use the platform default)
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            // Bring-your-own API (key stored encrypted, never returned to the browser)
            $table->boolean('use_own_key')->default(false);
            $table->string('own_base_url', 255)->nullable();
            $table->string('own_model', 120)->nullable();
            $table->text('own_api_key')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique('user_id', 'uq_ai_user_settings_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_user_settings');
    }
};
