<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('submitted_by');
            $table->string('title', 255);
            $table->string('application_number', 100)->nullable();
            $table->enum('status', ['draft', 'submitted', 'under_review', 'granted', 'rejected'])->default('draft');
            $table->date('filed_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
            $table->foreign('submitted_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patents');
    }
};
