<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advisory (non-official) project grades, e.g. a Teaching Assistant's. The official grade stays
 * in project_grades (one row per project, set by the doctor). One advisory row per grader.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_advisory_grades')) {
            return;
        }
        Schema::create('project_advisory_grades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('graded_by');
            $table->decimal('total_score', 6, 2)->nullable();
            $table->decimal('max_score', 6, 2)->default(0);
            $table->string('letter_grade', 2)->nullable();
            $table->string('status', 10)->default('draft'); // draft | final
            $table->text('overall_comments')->nullable();
            $table->json('criteria')->nullable();
            $table->dateTime('graded_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'graded_by'], 'uq_advisory_project_grader');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_advisory_grades');
    }
};
