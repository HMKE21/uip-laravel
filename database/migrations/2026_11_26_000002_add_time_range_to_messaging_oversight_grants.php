<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فتح محتوى إشراف الرسائل بالساعة والدقيقة (مش أيام كاملة بس): from_at / to_at بتوقيت التطبيق.
 * التصاريح القديمة (من غير from_at/to_at) بتفضل شغالة بالأيام الكاملة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('messaging_oversight_grants')) {
            return;
        }
        Schema::table('messaging_oversight_grants', function (Blueprint $table) {
            if (!Schema::hasColumn('messaging_oversight_grants', 'from_at')) {
                $table->dateTime('from_at')->nullable()->after('date_to');
            }
            if (!Schema::hasColumn('messaging_oversight_grants', 'to_at')) {
                $table->dateTime('to_at')->nullable()->after('from_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('messaging_oversight_grants')) {
            return;
        }
        Schema::table('messaging_oversight_grants', function (Blueprint $table) {
            foreach (['from_at', 'to_at'] as $c) {
                if (Schema::hasColumn('messaging_oversight_grants', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
