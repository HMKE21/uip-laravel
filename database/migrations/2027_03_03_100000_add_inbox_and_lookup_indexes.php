<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 indexes: the inbox poll (last message per conversation, hidden messages, attachment preview) and the
 * per-request lookups (roles, student / university rows, access grants).
 *
 * Safe to run more than once: skips missing tables/columns and anything an existing index already covers
 * (same leading columns). A failure on one index is logged and never stops the deploy.
 */
return new class extends Migration
{
    /** [table, columns, index name] */
    private const INDEXES = [
        // "latest visible message of a conversation" -> ORDER BY created_at DESC LIMIT 1 per conversation
        ['messages', ['conversation_id', 'created_at'], 'idx_messages_conv_time'],
        // NOT EXISTS (... WHERE message_id = ? AND user_id = ?)
        ['message_hidden_for_user', ['user_id', 'message_id'], 'idx_mhu_user_msg'],
        // first attachment kind / attachment count of the last message
        ['message_attachments', ['message_id'], 'idx_msg_attach_message'],
        // participants of a conversation excluding me, and my conversations that are not in the trash
        ['conversation_participants', ['conversation_id', 'user_id'], 'idx_cp_conv_user'],
        ['conversation_participants', ['user_id', 'deleted_at'], 'idx_cp_user_deleted'],
        // role / profile joins used on every authenticated request and every inbox row
        ['user_roles', ['user_id', 'assigned_at'], 'idx_user_roles_user_assigned'],
        ['students', ['user_id'], 'idx_students_user'],
        ['universities', ['user_id'], 'idx_universities_user'],
        ['account_access_grants', ['user_id', 'id'], 'idx_access_grants_user'],
        ['user_presence', ['last_seen_at'], 'idx_user_presence_seen'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $cols, $name]) {
            try {
                if (!Schema::hasTable($table) || !Schema::hasColumns($table, $cols) || $this->covered($table, $cols)) {
                    continue;
                }
                Schema::table($table, function (Blueprint $t) use ($cols, $name) {
                    $t->index($cols, $name);
                });
            } catch (\Throwable $e) {
                Log::warning("inbox index {$name} skipped: " . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as [$table, $cols, $name]) {
            try {
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            } catch (\Throwable $e) {
                Log::warning("inbox index {$name} not dropped: " . $e->getMessage());
            }
        }
    }

    private function covered(string $table, array $cols): bool
    {
        foreach (Schema::getIndexes($table) as $idx) {
            $have = array_map('strtolower', $idx['columns'] ?? []);
            if (array_slice($have, 0, count($cols)) === array_map('strtolower', $cols)) {
                return true;
            }
        }

        return false;
    }
};
