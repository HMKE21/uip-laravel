<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every user gets an Arabic and an English name (name_ar / name_en).
 * `full_name` stays as the derived legacy display column.
 *
 * Existing rows are backfilled from full_name: the single name lands in the
 * column matching its script, and is mirrored into the other until the owner
 * (or the university/admin) edits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'supervisors'] as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'name_ar')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->string('name_ar', 150)->nullable();
                $t->string('name_en', 150)->nullable();
            });
        }

        foreach (['users', 'supervisors'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'full_name')) {
                continue;
            }
            DB::table($table)->whereNull('name_ar')->orWhereNull('name_en')
                ->orderBy('id')
                ->select(['id', 'full_name'])
                ->chunkById(500, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        $name = trim((string) $row->full_name);
                        DB::table($table)->where('id', $row->id)->update(['name_ar' => $name, 'name_en' => $name]);
                    }
                });
        }

        // University accounts: their names live in universities.official_name_*.
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('universities') && Schema::hasColumn('universities', 'official_name_ar')) {
            DB::statement(
                "UPDATE users u JOIN universities x ON x.user_id = u.id
                 SET u.name_ar = x.official_name_ar, u.name_en = x.official_name_en
                 WHERE x.official_name_ar <> '' AND x.official_name_en <> ''"
            );
        }
    }

    public function down(): void
    {
        foreach (['users', 'supervisors'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'name_ar')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn(['name_ar', 'name_en']);
                });
            }
        }
    }
};
