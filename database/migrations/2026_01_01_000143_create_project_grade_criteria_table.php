<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_grade_criteria', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_grade_id');
            $table->string('criterion', 200);
            $table->decimal('max_score', 6, 2);
            $table->decimal('score', 6, 2)->nullable();
            $table->string('comments', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('project_grade_id')->references('id')->on('project_grades')->onDelete('cascade');
            $table->index(['project_grade_id', 'sort_order'], 'idx_project_grade_criteria_grade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_grade_criteria');
    }
};
