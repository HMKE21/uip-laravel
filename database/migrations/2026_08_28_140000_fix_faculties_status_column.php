<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * عمود `status` في `faculties` كان ENUM من المشروع القديم (زمان كانت
 * قيمه المعروفة active/inactive بس)، فمكنش بيقبل 'archived' اللي
 * ضفناها مع ميزة الأرشفة → بيرمي "Data truncated for column 'status'".
 * بنحوّله لـ VARCHAR عشان يقبل أي قيمة status جاية دلوقتي أو مستقبلًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE faculties MODIFY status VARCHAR(20) NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE faculties MODIFY status ENUM('active','inactive') NOT NULL DEFAULT 'active'");
    }
};
