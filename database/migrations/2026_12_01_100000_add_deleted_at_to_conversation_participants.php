<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سلة محذوفات المحادثات (لكل مستخدم): deleted_at بيتحط لما العضو يحذف محادثة؛ بتفضل 30 يوم
 * (ConversationTrashService::RETENTION_DAYS) يقدر يسترجعها أو يحذفها نهائيًا، وبعدها
 * أمر messaging:purge-trash بيحذفها نهائيًا تلقائيًا. المحادثة بتفضل موجودة للأعضاء التانيين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dateTime('deleted_at')->nullable()->after('is_archived');
            $table->index(['user_id', 'deleted_at'], 'idx_cp_user_deleted');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropIndex('idx_cp_user_deleted');
            $table->dropColumn('deleted_at');
        });
    }
};
