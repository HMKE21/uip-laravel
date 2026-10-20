<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Joker" account: a user who holds every role and can switch between them
 * from the UI. users.is_joker marks the account (set by an admin only);
 * user_sessions.active_role remembers which role the session is acting as,
 * so refresh-token rotation does not snap it back to the primary role.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'is_joker')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_joker')->default(false);
            });
        }
        if (Schema::hasTable('user_sessions') && !Schema::hasColumn('user_sessions', 'active_role')) {
            Schema::table('user_sessions', function (Blueprint $table) {
                $table->string('active_role', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'is_joker')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_joker'));
        }
        if (Schema::hasColumn('user_sessions', 'active_role')) {
            Schema::table('user_sessions', fn (Blueprint $t) => $t->dropColumn('active_role'));
        }
    }
};
