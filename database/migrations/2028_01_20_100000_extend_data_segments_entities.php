<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data Analysis Portal — Segments: توسيع الكيانات المسموحة من
 * (projects, users) لتشمل students و exams و courses.
 * data_segments.entity كان ENUM('users','projects')، فبنحوّله لـ
 * VARCHAR(20) (التحقق من القيم المسموحة في الكنترولر/الريبوزيتوري) بدل
 * ما نعدّل الـ enum كل ما نضيف كيان.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('data_segments') || !Schema::hasColumn('data_segments', 'entity')) {
            return;
        }

        Schema::table('data_segments', function (Blueprint $table) {
            $table->string('entity', 20)->default('projects')->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('data_segments')) {
            return;
        }
        DB::table('data_segments')->whereNotIn('entity', ['users', 'projects'])->delete();
        Schema::table('data_segments', function (Blueprint $table) {
            $table->enum('entity', ['users', 'projects'])->default('projects')->change();
        });
    }
};
