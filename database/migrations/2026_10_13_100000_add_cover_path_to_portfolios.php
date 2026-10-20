<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صورة غلاف (خلفية الهيرو) للصفحة العامة /p/{uuid}. نفس منطق avatar_path:
 * مسار نسبي جوه public/ (uploads/avatars/covers/{userId}/...).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('portfolios', 'cover_path')) {
            Schema::table('portfolios', function (Blueprint $table) {
                $table->string('cover_path', 255)->nullable()->after('theme');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('portfolios', 'cover_path')) {
            Schema::table('portfolios', function (Blueprint $table) {
                $table->dropColumn('cover_path');
            });
        }
    }
};
