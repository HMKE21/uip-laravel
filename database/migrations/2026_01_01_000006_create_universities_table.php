<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('universities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('official_name_ar', 200);
            $table->string('official_name_en', 200);
            $table->string('country', 100);
            $table->string('city', 100)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->enum('verification_status', ['unverified', 'pending', 'verified', 'rejected'])->default('pending');
            $table->dateTime('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->boolean('is_public')->default(1);
            $table->unsignedInteger('verification_period_days')->nullable();
            $table->dateTime('verification_expires_at')->nullable();
            $table->dateTime('verification_expiry_notified_at')->nullable();
            $table->boolean('auto_reverify_enabled')->default(1);
            $table->string('slug', 255)->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id']);
            $table->unique(['slug'], 'uq_universities_slug');
            $table->index(['verification_status'], 'idx_universities_status');
            $table->index(['verification_expires_at'], 'idx_universities_verification_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('universities');
    }
};
