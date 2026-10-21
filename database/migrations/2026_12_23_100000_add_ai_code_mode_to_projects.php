<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وضع مساعد الكود (AI) لكل مشروع — بيضبطه الدكتور المشرف: NULL = من غير تخصيص (إعدادات الأدمن العامة)،
 * 'off' = مقفول للمشروع، 'tutor' = يشرح ويلمّح بس، 'assistant' = يدّي حلول/تعديلات. شوف AiCodeAssistService::projectMode().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('projects', 'ai_code_mode')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->string('ai_code_mode', 12)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'ai_code_mode')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('ai_code_mode');
            });
        }
    }
};
