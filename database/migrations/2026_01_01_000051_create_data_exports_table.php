<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('export_type', 60);
            $table->enum('format', ['csv', 'xlsx', 'pdf']);
            $table->json('filters')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('completed_at')->nullable();
            $table->string('email_to', 500)->nullable();
            $table->string('batch_id', 32)->nullable();
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
