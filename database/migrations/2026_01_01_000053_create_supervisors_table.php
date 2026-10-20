<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('university_id');
            $table->string('full_name', 150);
            $table->string('email', 190);
            $table->string('department', 150)->nullable();
            $table->string('title', 100)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->dateTime('invited_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('permissions', 255)->default('');
            $table->enum('invitation_status', ['pending', 'accepted', 'expired'])->default('pending');
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->foreign('university_id')->references('id')->on('universities')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->unique(['university_id', 'email'], 'uq_supervisors_university_email');
            $table->unique(['user_id']);
            $table->index(['university_id'], 'idx_supervisors_university');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisors');
    }
};
