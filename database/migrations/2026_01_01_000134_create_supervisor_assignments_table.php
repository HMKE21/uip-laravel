<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisor_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supervisor_id');
            $table->unsignedBigInteger('university_id');
            $table->string('scope_type', 20);
            $table->string('scope_value', 150)->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('supervisor_id')->references('id')->on('supervisors')->onDelete('cascade');
            $table->foreign('university_id')->references('id')->on('universities')->onDelete('cascade');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->index(['supervisor_id'], 'idx_supervisor_assignments_supervisor');
            $table->index(['university_id'], 'idx_supervisor_assignments_university');
            $table->index(['scope_type', 'scope_value'], 'idx_supervisor_assignments_scope');
            $table->index(['project_id'], 'idx_supervisor_assignments_project');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_assignments');
    }
};
