<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** علامة "رد المساعد اتقطع عند حد الطول" — الواجهة بتعرض زر «كمّل» على آخر رد مقطوع حتى بعد إعادة فتح المحادثة. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_messages') && !Schema::hasColumn('ai_messages', 'is_truncated')) {
            Schema::table('ai_messages', function (Blueprint $table) {
                $table->boolean('is_truncated')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_messages', 'is_truncated')) {
            Schema::table('ai_messages', function (Blueprint $table) {
                $table->dropColumn('is_truncated');
            });
        }
    }
};
