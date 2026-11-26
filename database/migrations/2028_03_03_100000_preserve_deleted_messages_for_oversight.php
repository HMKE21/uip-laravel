<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إشراف الرسائل: المحتوى اللي اليوزر يحذفه (حذف للجميع / تراجع عن الإرسال / حذف مرفق) بيفضل محفوظ للأدمن بس.
 *  - messages.original_body / original_metadata: نسخة من النص وقت الحذف (اليوزرز بيشوفوا tombstone زي الأول).
 *  - message_attachments.deleted_at / deleted_by: حذف ناعم للمرفق (الملف بيفضل على السيرفر، واليوزرز مبيشوفوهوش).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'original_body')) {
                $table->longText('original_body')->nullable();
            }
            if (!Schema::hasColumn('messages', 'original_metadata')) {
                $table->longText('original_metadata')->nullable();
            }
        });
        Schema::table('message_attachments', function (Blueprint $table) {
            if (!Schema::hasColumn('message_attachments', 'deleted_at')) {
                $table->dateTime('deleted_at')->nullable();
            }
            if (!Schema::hasColumn('message_attachments', 'deleted_by')) {
                $table->unsignedBigInteger('deleted_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            foreach (['original_body', 'original_metadata'] as $c) {
                if (Schema::hasColumn('messages', $c)) { $table->dropColumn($c); }
            }
        });
        Schema::table('message_attachments', function (Blueprint $table) {
            foreach (['deleted_at', 'deleted_by'] as $c) {
                if (Schema::hasColumn('message_attachments', $c)) { $table->dropColumn($c); }
            }
        });
    }
};
