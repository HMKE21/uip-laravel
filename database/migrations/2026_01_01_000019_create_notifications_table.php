<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 80);
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link_url', 255)->nullable();
            $table->boolean('is_read')->default(0);
            $table->dateTime('read_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->string('priority', 10)->default('normal');
            $table->string('category', 50)->nullable();
            $table->boolean('is_pinned')->default(0);
            $table->boolean('is_important')->default(0);
            $table->boolean('is_archived')->default(0);
            $table->dateTime('archived_at')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->dateTime('deleted_at')->nullable();
            $table->string('deleted_reason', 255)->nullable();
            $table->unsignedSmallInteger('occurrence_count')->default(1);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'is_read'], 'idx_notifications_user_read');
            $table->index(['user_id', 'is_deleted', 'is_archived', 'is_read'], 'idx_notifications_user_status');
            $table->index(['user_id', 'is_pinned'], 'idx_notifications_user_pinned');
            $table->index(['user_id', 'is_important'], 'idx_notifications_user_important');
            $table->index(['user_id', 'category'], 'idx_notifications_category');
            $table->index(['user_id', 'created_at'], 'idx_notifications_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
