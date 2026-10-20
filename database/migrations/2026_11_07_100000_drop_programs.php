<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the "program" concept entirely — the department (القسم) is the unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['students', 'student_university_requests', 'exams', 'exam_targets'] as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'program_id')) {
                continue;
            }
            if ($tableName === 'exam_targets') {
                // Rows that targeted ONLY a program would widen to "whole faculty" — remove them.
                DB::table('exam_targets')->whereNotNull('program_id')->whereNull('department_id')->delete();
            }
            try { Schema::table($tableName, function (Blueprint $t) { $t->dropForeign(['program_id']); }); } catch (\Throwable $e) {}
            try { Schema::table($tableName, function (Blueprint $t) { $t->dropIndex(['program_id']); }); } catch (\Throwable $e) {}
            Schema::table($tableName, function (Blueprint $t) { $t->dropColumn('program_id'); });
        }

        if (Schema::hasTable('graduation_records')) {
            foreach (['program_name_ar', 'program_name_en'] as $col) {
                if (Schema::hasColumn('graduation_records', $col)) {
                    Schema::table('graduation_records', function (Blueprint $t) use ($col) { $t->dropColumn($col); });
                }
            }
        }

        Schema::dropIfExists('programs');
    }

    public function down(): void
    {
        // Intentionally irreversible.
    }
};
