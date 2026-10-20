<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الـ AI بيكتب الإجابة النموذجية بنفسه للأسئلة المقالية لو المدرس مدخلهاش.
 * عمود منفصل عن model_answer عشان إجابة المدرس (لو موجودة) تفضل هي الأولوية
 * ومنتلخبطش بين اللي كتبه المدرس واللي ولّده الـ AI.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('questions', 'ai_model_answer')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->text('ai_model_answer')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('questions', 'ai_model_answer')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->dropColumn('ai_model_answer');
            });
        }
    }
};
