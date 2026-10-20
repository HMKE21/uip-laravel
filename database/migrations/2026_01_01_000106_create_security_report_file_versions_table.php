<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_report_file_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('security_report_file_id');
            $table->unsignedInteger('version_number');
            $table->string('storage_path', 255);
            $table->string('original_filename', 255);
            $table->string('file_extension', 20);
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('security_report_file_id')->references('id')->on('security_report_files')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['security_report_file_id', 'version_number'], 'uniq_srf_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_report_file_versions');
    }
};
