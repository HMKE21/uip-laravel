<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * باسورد دخول اختياري للامتحان — الدكتور هو اللي يحدد. بيتخزن مشفّر (hash) ومش بيرجع في أي response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (!Schema::hasColumn('exams', 'access_password')) {
                $table->string('access_password', 255)->nullable()->after('single_session_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (Schema::hasColumn('exams', 'access_password')) {
                $table->dropColumn('access_password');
            }
        });
    }
};
