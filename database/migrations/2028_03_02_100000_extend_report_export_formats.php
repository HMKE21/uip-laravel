<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * صفحات Reports و Exports بقت بتدعم كل صيغ المنصة (pdf, docx, xlsx, csv,
 * json, html, md, txt, xml) — لازم أعمدة format (ENUM) في الجداول الأربعة
 * تقبل القيم الجديدة، وإلا الإدخال بيفشل على MySQL.
 */
return new class extends Migration
{
    private const FORMATS = ['pdf', 'docx', 'xlsx', 'csv', 'json', 'html', 'md', 'txt', 'xml'];

    /** table => [old formats (for rollback), default (null = no default)] */
    private const TABLES = [
        'reports'          => [['pdf', 'csv', 'xlsx', 'json', 'docx'], 'pdf'],
        'report_schedules' => [['csv', 'pdf', 'xlsx'], 'csv'],
        'data_exports'     => [['csv', 'xlsx', 'pdf'], null],
        'export_schedules' => [['csv', 'pdf', 'xlsx'], 'csv'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => [, $default]) {
            $this->alter($table, self::FORMATS, $default);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => [$old, $default]) {
            // الصفوف اللي بصيغ جديدة مش هتدخل في الـ ENUM القديم — نرجّعها لأقرب صيغة قديمة.
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'format')) {
                DB::table($table)->whereNotIn('format', $old)->update(['format' => $old[0]]);
            }
            $this->alter($table, $old, $default);
        }
    }

    private function alter(string $table, array $formats, ?string $default): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'format')) {
            return;
        }
        // ENUM حقيقي بس على MySQL/MariaDB؛ باقي المحركات مش محتاجة تعديل هنا.
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $list = implode(',', array_map(fn ($f) => "'" . $f . "'", $formats));
        $defaultSql = $default !== null ? " DEFAULT '" . $default . "'" : '';
        DB::statement("ALTER TABLE `{$table}` MODIFY `format` ENUM({$list}) NOT NULL{$defaultSql}");
    }
};
