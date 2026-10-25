<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meeting chat history policy ("keep" vs "hide" for people who join later).
 *
 * `chat_from_message_id` is the id of the newest chat message that already existed the first time this person opened the
 * room's chat. When the host picks "hide history", a person only gets public messages with an id GREATER than that mark.
 * It is stored once (first entry) and never moved, so refreshing or re-joining keeps showing what they already saw.
 *
 * It lives on both actor tables because a meeting actor is either a registered participant or an admitted guest.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['meeting_participants', 'meeting_join_requests'] as $table) {
            if (!Schema::hasColumn($table, 'chat_from_message_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('chat_from_message_id')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['meeting_participants', 'meeting_join_requests'] as $table) {
            if (Schema::hasColumn($table, 'chat_from_message_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('chat_from_message_id');
                });
            }
        }
    }
};
