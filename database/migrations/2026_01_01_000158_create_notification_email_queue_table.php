<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_email_queue', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('notification_id')->nullable();
            $table->string('kind', 10)->default('single');
            $table->string('to_email', 190);
            $table->string('to_name', 190)->nullable();
            $table->string('subject', 200);
            $table->text('body')->nullable();
            $table->string('link_url', 255)->nullable();
            $table->string('locale', 5)->default('ar');
            $table->string('status', 10)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->dateTime('next_attempt_at');
            $table->string('last_error', 500)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('notification_id')->references('id')->on('notifications')->onDelete('set null');
            $table->index(['status', 'next_attempt_at'], 'idx_notif_email_queue_due');
            $table->index(['user_id'], 'idx_notif_email_queue_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_email_queue');
    }
};
