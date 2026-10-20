<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_grades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('graded_by');
            $table->decimal('total_score', 6, 2)->nullable();
            $table->decimal('max_score', 6, 2)->default(0);
            $table->string('letter_grade', 2)->nullable();
            $table->enum('status', ['draft', 'final'])->default('draft');
            $table->text('overall_comments')->nullable();
            $table->dateTime('graded_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('graded_by')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['project_id'], 'uq_project_grades_project');
            $table->index(['status'], 'idx_project_grades_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_grades');
    }
};
