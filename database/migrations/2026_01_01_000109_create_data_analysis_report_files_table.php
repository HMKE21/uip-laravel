<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_analysis_report_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('uploaded_by');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('original_filename', 255);
            $table->string('file_extension', 20);
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->string('storage_path', 255);
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);
            $table->boolean('is_archived')->default(0);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('data_analysis_report_categories')->onDelete('set null');
            $table->index(['uploaded_by'], 'idx_darf_uploader');
            $table->index(['is_archived'], 'idx_darf_archived');
            $table->index(['category_id'], 'idx_darf_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_analysis_report_files');
    }
};
