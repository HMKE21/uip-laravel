<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft delete for departments: deleted_at hides the department everywhere, but the row,
 * its students (students.department_id), staff and exams are untouched, so restoring it
 * brings back exactly what was there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('departments', 'deleted_at')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->dateTime('deleted_at')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->index(['deleted_at'], 'idx_departments_deleted_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('departments', 'deleted_at')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->dropIndex('idx_departments_deleted_at');
                $table->dropColumn(['deleted_at', 'deleted_by']);
            });
        }
    }
};
