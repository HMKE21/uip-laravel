<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل التصحيح بقى بيحفظ التعليق (feedback) قبل/بعد كل تغيير، مش الدرجة بس،
 * عشان الدكتور يشوف إيه اللي اتغير بالظبط بين تصحيح الـ AI وتعديله.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_grade_history', function (Blueprint $table) {
            if (!Schema::hasColumn('exam_grade_history', 'previous_feedback')) {
                $table->text('previous_feedback')->nullable();
            }
            if (!Schema::hasColumn('exam_grade_history', 'new_feedback')) {
                $table->text('new_feedback')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('exam_grade_history', function (Blueprint $table) {
            $table->dropColumn(['previous_feedback', 'new_feedback']);
        });
    }
};
