<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * People without a UIP account could never get in: `allow_guests` defaulted to false, so every meeting rejected them with
 * "Guest access is not enabled". Guests are now allowed by default. They still have to be admitted by the host through the
 * waiting room (which is on by default), so this does not let strangers straight in.
 *
 * Meetings that are still open (not ended / cancelled) are switched on too, otherwise links that were already shared would keep
 * rejecting guests. A host can still turn it off per meeting.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->boolean('allow_guests')->default(true)->change();
        });

        DB::table('meetings')->whereNotIn('status', ['ended', 'cancelled'])->update(['allow_guests' => true]);
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->boolean('allow_guests')->default(false)->change();
        });
    }
};
