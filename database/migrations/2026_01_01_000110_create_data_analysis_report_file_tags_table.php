<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_analysis_report_file_tags', function (Blueprint $table) {
            $table->unsignedBigInteger('data_analysis_report_file_id');
            $table->unsignedBigInteger('tag_id');
            $table->foreign('data_analysis_report_file_id', 'dar_file_tags_file_fk')->references('id')->on('data_analysis_report_files')->onDelete('cascade');
            $table->foreign('tag_id', 'dar_file_tags_tag_fk')->references('id')->on('data_analysis_report_tags')->onDelete('cascade');
            $table->primary(['data_analysis_report_file_id', 'tag_id'], 'dar_file_tags_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_analysis_report_file_tags');
    }
};
