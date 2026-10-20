<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('academic_staff_id');
            $table->unsignedBigInteger('academic_rank_id');
            $table->string('scope_type', 20);
            $table->unsignedBigInteger('scope_id');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(1);
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('academic_staff_id')->references('id')->on('academic_staff')->onDelete('cascade');
            $table->foreign('academic_rank_id')->references('id')->on('academic_ranks')->onDelete('restrict');
            $table->foreign('assigned_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['academic_staff_id'], 'idx_staff_assignments_staff');
            $table->index(['scope_type', 'scope_id'], 'idx_staff_assignments_scope');
            $table->index(['is_active'], 'idx_staff_assignments_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_assignments');
    }
};
