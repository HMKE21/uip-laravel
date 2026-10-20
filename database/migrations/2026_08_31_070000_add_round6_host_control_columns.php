<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند "UIP Meetings" — Round 6 (Host Controls): بند 5 (Meeting
 * Controls — "Lock meeting")، بند 10 (Participant Management — Mute/
 * Remove/Promote/Demote/Make host). additive بالكامل زي كل migration
 * قبلها في الموديول ده.
 *
 * meetings.locked: قفل الاجتماع كله ضد أي انضمام جديد (بعكس
 * screen_sharing_locked في Round 5 اللي بيقفل مشاركة الشاشة بس) —
 * راجع docblock MeetingLobbyService::isJoinable() للمنطق الفعلي.
 * الهوست/co-host نفسهم دايمًا بيدخلوا برضو (bypassesWaitingRoom نفس
 * منطق Round 2) — القفل ده لحد جديد بس.
 *
 * removed_by_user_id/removed_at على meeting_participants **و**
 * meeting_join_requests (نفس فلسفة migration 2026_08_31_030000 —
 * أعمدة حالة موازية على الجدولين عشان اليوزر المسجّل والضيف يتعاملوا
 * بنفس المنطق في MeetingHostControlService): سجل تدقيق "مين شال مين"
 * (بند 38 — Audit & Security Logs) منفصل عن AuditLogService العام
 * (اللي بيسجل الحدث كمان) — العمودين هنا مفيدين لعرض مباشر في
 * Participant Panel نفسه ("Removed by <host>") من غير query على جدول
 * الـ audit العام في كل مرة.
 *
 * ملحوظة تصميم مهمة: مفيش قيمة enum جديدة اتضافت هنا عمدًا —
 * meeting_participants.status أصلًا فيها 'removed' من Round 1 (كان
 * محسوب من الأول)، وmeeting_join_requests.status بنعيد استخدام
 * 'rejected' الموجودة (بدل ما نضيف 'removed' جديدة) عشان تغيير enum
 * على عمود موجود بالفعل محتاج doctrine/dbal (وميتفقش مع sqlite CHECK
 * constraint اللي Laravel بيولّده لعمود enum() على SQLite أصلًا) —
 * راجع docblock MeetingHostControlService::removeParticipant() ليه
 * 'rejected' هنا معناها الفعلي "القبول اتلغى" مش بس "الطلب اتّرفض
 * الأول".
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->boolean('locked')->default(false)->after('screen_sharing_locked');
        });

        foreach (['meeting_participants', 'meeting_join_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('removed_by_user_id')->nullable()->after('screen_share_allowed')
                    ->constrained('users')->nullOnDelete();
                $table->timestamp('removed_at')->nullable()->after('removed_by_user_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['meeting_participants', 'meeting_join_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('removed_by_user_id');
                $table->dropColumn('removed_at');
            });
        }

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('locked');
        });
    }
};
