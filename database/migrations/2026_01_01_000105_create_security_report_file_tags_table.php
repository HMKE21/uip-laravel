<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_report_file_tags', function (Blueprint $table) {
            $table->unsignedBigInteger('security_report_file_id');
            $table->unsignedBigInteger('tag_id');
            $table->foreign('security_report_file_id')->references('id')->on('security_report_files')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('security_report_tags')->onDelete('cascade');
            $table->primary(['security_report_file_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_report_file_tags');
    }
};
