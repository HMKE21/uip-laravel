<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_analysis_report_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('report_file_id');
            $table->unsignedBigInteger('parent_comment_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->text('body');
            $table->boolean('is_note')->default(0);
            $table->boolean('is_resolved')->default(0);
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('report_file_id')->references('id')->on('data_analysis_report_files')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['report_file_id'], 'idx_darc_file');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_analysis_report_comments');
    }
};
