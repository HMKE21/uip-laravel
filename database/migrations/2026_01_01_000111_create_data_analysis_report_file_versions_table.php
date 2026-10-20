<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_analysis_report_file_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('data_analysis_report_file_id');
            $table->unsignedInteger('version_number');
            $table->string('storage_path', 255);
            $table->string('original_filename', 255);
            $table->string('file_extension', 20);
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('data_analysis_report_file_id', 'dar_file_versions_file_fk')->references('id')->on('data_analysis_report_files')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['data_analysis_report_file_id', 'version_number'], 'uniq_darf_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_analysis_report_file_versions');
    }
};
