<?php

use App\Services\UniversityMergeService;
use App\Support\UniversityName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One university = one name, enforced by the database.
 *
 *  1. Universities that already share a name are merged (students + all data move to the one
 *     that is kept; the extra is soft-deleted — see UniversityMergeService).
 *  2. name_key_ar / name_key_en hold the normalised names (UniversityName::normalize) of live
 *     universities and carry UNIQUE indexes; NULL for deleted ones so their names can be reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('universities')) {
            return;
        }

        if (!Schema::hasColumn('universities', 'name_key_ar')) {
            Schema::table('universities', function (Blueprint $table) {
                $table->string('name_key_ar', 200)->nullable();
                $table->string('name_key_en', 200)->nullable();
            });
        }

        // 1. merge what is already duplicated
        $service = app(UniversityMergeService::class);
        $failed = array_filter($service->mergeAll(), fn ($r) => !$r['ok']);
        if ($failed) {
            $msg = implode('; ', array_map(fn ($r) => "#{$r['duplicate']} -> #{$r['keep']}: {$r['error']}", $failed));
            throw new RuntimeException('Duplicate universities could not be merged automatically (' . $msg . '). Fix them, or run `php artisan universities:merge-duplicates`, then migrate again.');
        }

        // 2. keys for every live university (deleted ones stay NULL)
        DB::table('universities')->whereNotNull('deleted_at')->update(['name_key_ar' => null, 'name_key_en' => null]);
        DB::table('universities')->whereNull('deleted_at')->orderBy('id')->each(function ($u) {
            DB::table('universities')->where('id', $u->id)
                ->update(UniversityName::keys($u->official_name_ar, $u->official_name_en));
        });

        Schema::table('universities', function (Blueprint $table) {
            $table->unique('name_key_ar', 'uq_universities_name_key_ar');
            $table->unique('name_key_en', 'uq_universities_name_key_en');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('universities') || !Schema::hasColumn('universities', 'name_key_ar')) {
            return;
        }
        Schema::table('universities', function (Blueprint $table) {
            $table->dropUnique('uq_universities_name_key_ar');
            $table->dropUnique('uq_universities_name_key_en');
            $table->dropColumn(['name_key_ar', 'name_key_en']);
        });
    }
};
