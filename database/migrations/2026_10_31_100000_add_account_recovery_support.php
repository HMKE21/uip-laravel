<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * استرجاع الحسابات المحذوفة + دمجها:
 *  - users.original_email      : الإيميل الأصلي للحساب المحذوف بعد ما بيتحوّل لـ tombstone (deleted-<id>-<time>-email)
 *  - users.merged_into_user_id : الحساب المحذوف اتدمج في حساب تاني (مبيترجعش ذاتيًا تاني).
 *  - account_merges            : سجل بكل عملية دمج (إيه اللي اتنقل وإيه اللي اتساب) — للمراجعة.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'original_email')) {
                $table->string('original_email', 190)->nullable();
                $table->index(['original_email'], 'idx_users_original_email');
            }
            if (!Schema::hasColumn('users', 'merged_into_user_id')) {
                $table->unsignedBigInteger('merged_into_user_id')->nullable();
            }
        });

        if (!Schema::hasTable('account_merges')) {
            Schema::create('account_merges', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('from_user_id')->index();
                $table->unsignedBigInteger('into_user_id')->index();
                $table->unsignedBigInteger('performed_by_user_id')->nullable();
                $table->string('mode', 20)->default('self'); // self | admin
                $table->json('groups')->nullable();
                $table->json('moved')->nullable();
                $table->json('left_behind')->nullable();
                $table->dateTime('created_at')->useCurrent();
            });
        }

        // الحسابات اللي اتحوّل إيميلها قبل الميجريشن: نطلّع الإيميل الأصلي من الـ tombstone.
        DB::table('users')->where('email', 'like', 'deleted-%')->whereNotNull('deleted_at')->whereNull('original_email')
            ->orderBy('id')->get(['id', 'email'])->each(function ($row) {
                $orig = preg_replace('/^deleted-' . (int) $row->id . '-\d+-/', '', (string) $row->email);
                if ($orig !== $row->email) {
                    DB::table('users')->where('id', $row->id)->update(['original_email' => $orig]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_merges');
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'original_email')) {
                $table->dropIndex('idx_users_original_email');
                $table->dropColumn('original_email');
            }
            if (Schema::hasColumn('users', 'merged_into_user_id')) {
                $table->dropColumn('merged_into_user_id');
            }
        });
    }
};
