<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which portal a notification belongs to (admin / student / university / ...). A "joker" account holds every role and
 * switches between them on the same user_id, so without this column the student view showed the admin's notifications.
 * NULL = no portal could be told (shown in every role). Filled by NotificationService::notify(); backfilled here from link_url.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('notifications', 'portal')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->string('portal', 30)->nullable()->after('link_url');
                $table->index(['user_id', 'portal'], 'idx_notifications_user_portal');
            });
        }

        foreach (['admin', 'student', 'university', 'security', 'data-analysis', 'supervisor', 'academic-staff', 'faculty', 'company', 'investor', 'researcher', 'designer'] as $p) {
            DB::table('notifications')->whereNull('portal')
                ->where(function ($q) use ($p) {
                    $q->where('link_url', 'like', "/{$p}")->orWhere('link_url', 'like', "/{$p}/%")->orWhere('link_url', 'like', "/{$p}?%");
                })
                ->update(['portal' => $p]);
        }
        DB::table('notifications')->whereNull('portal')->where('type', 'like', 'system\_%')->update(['portal' => 'admin']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('notifications', 'portal')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropIndex('idx_notifications_user_portal');
                $table->dropColumn('portal');
            });
        }
    }
};
