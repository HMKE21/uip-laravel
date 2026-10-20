<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('uploaded_by');
            $table->string('category', 50)->default('general');
            $table->string('original_name', 255);
            $table->string('stored_path', 255);
            $table->string('mime_type', 100)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->boolean('is_deleted')->default(0);
            $table->dateTime('deleted_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('cascade');
            $table->index(['uploaded_by', 'is_deleted'], 'idx_api_files_uploaded_by');
            $table->index(['category'], 'idx_api_files_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_files');
    }
};
