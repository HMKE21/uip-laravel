<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (!Schema::hasColumn('exams', 'exam_type')) {
                $table->string('exam_type', 20)->default('midterm')->after('subject');
            }
            if (!Schema::hasColumn('exams', 'auto_submit_on_timeout')) {
                $table->boolean('auto_submit_on_timeout')->default(true)->after('randomize_options');
            }
            if (!Schema::hasColumn('exams', 'allow_back_navigation')) {
                $table->boolean('allow_back_navigation')->default(false)->after('auto_submit_on_timeout');
            }
            if (!Schema::hasColumn('exams', 'show_answer_review')) {
                $table->boolean('show_answer_review')->default(true)->after('allow_back_navigation');
            }
            if (!Schema::hasColumn('exams', 'show_score_only')) {
                $table->boolean('show_score_only')->default(false)->after('show_answer_review');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            foreach (['exam_type', 'show_score_only', 'show_answer_review', 'allow_back_navigation', 'auto_submit_on_timeout'] as $c) {
                if (Schema::hasColumn('exams', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
