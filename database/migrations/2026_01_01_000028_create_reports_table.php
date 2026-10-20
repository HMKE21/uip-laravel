<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('generated_by');
            $table->string('report_type', 80);
            $table->enum('format', ['pdf', 'csv', 'xlsx', 'json', 'docx'])->default('pdf');
            $table->string('file_path', 255)->nullable();
            $table->json('parameters')->nullable();
            $table->enum('status', ['queued', 'generating', 'ready', 'failed'])->default('queued');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->foreign('generated_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
