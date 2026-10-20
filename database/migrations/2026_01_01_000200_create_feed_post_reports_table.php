<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_post_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('reporter_user_id');
            $table->string('reason', 500);
            $table->enum('status', ['pending', 'actioned', 'dismissed'])->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('resolution_notes', 500)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('post_id')->references('id')->on('feed_posts')->onDelete('cascade');
            $table->foreign('reporter_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['post_id', 'reporter_user_id'], 'uq_feed_post_reports_post_reporter');
            $table->index(['status'], 'idx_feed_post_reports_status');
            $table->index(['post_id'], 'idx_feed_post_reports_post');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_post_reports');
    }
};
