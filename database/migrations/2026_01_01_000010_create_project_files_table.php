<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('uploaded_by');
            $table->enum('file_type', ['document', 'image', 'video', 'video_link', 'presentation', 'source_code', 'other'])->default('document');
            $table->string('file_path', 255)->nullable();
            $table->string('original_name', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_latest')->default(1);
            $table->unsignedBigInteger('root_file_id')->nullable();
            $table->string('external_url', 500)->nullable();
            $table->string('caption', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_featured')->default(0);
            $table->string('thumbnail_path', 255)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('cascade');
            $table->index(['project_id', 'is_latest'], 'idx_project_files_latest');
            $table->index(['project_id', 'sort_order'], 'idx_project_files_gallery');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_files');
    }
};
