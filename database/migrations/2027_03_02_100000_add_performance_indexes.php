<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس أداء إضافية للجداول اللي بتتسأل في كل ريكويست/كل poll.
 *
 * آمنة تتشغّل أكتر من مرة: بتتأكد إن الجدول والأعمدة موجودة، وإن مفيش فهرس قائم
 * بيبدأ بنفس الأعمدة قبل ما تضيف. أي فشل في فهرس واحد بيتسجّل في اللوج وميوقّفش الـ deploy.
 */
return new class extends Migration
{
    /** [table, columns, index name] */
    private const INDEXES = [
        // inbox / "مين في محادثاتي": الـ PK (conversation_id, user_id) مش بيخدم البحث بالـ user_id لوحده.
        ['conversation_participants', ['user_id'], 'idx_cp_user'],
        // قايمة إشعارات المستخدم مرتبة بالأحدث.
        ['notifications', ['user_id', 'created_at'], 'idx_notifications_user_time'],
        // تنضيف الـ refresh tokens المنتهية.
        ['refresh_tokens', ['expires_at'], 'idx_refresh_tokens_expires'],
        // تنضيف/فحص الجلسات المنتهية.
        ['user_sessions', ['is_active', 'expires_at'], 'idx_sessions_active_expires'],
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
                Log::warning("performance index {$name} skipped: " . $e->getMessage());
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
                Log::warning("performance index {$name} not dropped: " . $e->getMessage());
            }
        }
    }

    /** فيه فهرس قائم أول أعمدته = أعمدتنا (بنفس الترتيب)؟ */
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
