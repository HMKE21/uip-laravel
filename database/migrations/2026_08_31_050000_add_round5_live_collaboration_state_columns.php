<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند "UIP Meetings" — Round 5 (Live Collaboration): بند 11 (Raise
 * Hand & Reactions — الجزء المُخزّن بس، الـ Reactions نفسها في بند 11
 * ephemeral بالكامل زي docblock ParticipantReactionSent)، بند 9
 * (Screen Sharing — host policy). additive بالكامل زي كل migration
 * قبلها في الموديول ده.
 *
 * hand_raised/hand_raised_at: نفس فلسفة mic_enabled/camera_enabled
 * (migration 2026_08_31_030000) — آخر حالة معروفة على نفس الجدولين
 * (meeting_participants + meeting_join_requests) عشان اليوزر المسجّل
 * والضيف يتعاملوا بنفس المنطق في MeetingSignalingService. hand_raised_at
 * (مش بس boolean) عشان الهوست يقدر يشوف/يرتب طوابير رفع الإيد
 * (FIFO — مين رفع الأول) زي waiting room بالظبط.
 *
 * screen_share_allowed: nullable tri-state مقصود (مش boolean عادي) —
 * null = "يتبع قفل الاجتماع العام" (meetings.screen_sharing_locked)،
 * true = الهوست سمح لهذا الشخص تحديدًا حتى لو الاجتماع مقفول عمومًا،
 * false = الهوست منع هذا الشخص تحديدًا حتى لو الاجتماع مفتوح عمومًا.
 * راجع docblock MeetingSignalingService::canShareScreen() للمنطق الكامل.
 *
 * meetings.screen_sharing_locked: القفل العام بتاع الاجتماع كله (بند 9
 * — "Allow screen sharing" / "Disable screen sharing" من ناحية
 * الهوست) — منفصل عن settings JSON (Round 1) عمدًا لأنه حالة تشغيلية
 * بتتغير كتير أثناء الاجتماع الحي نفسه، مش إعداد وقت الإنشاء.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['meeting_participants', 'meeting_join_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->boolean('hand_raised')->default(false)->after('connection_quality');
                $table->timestamp('hand_raised_at')->nullable()->after('hand_raised');
                $table->boolean('screen_share_allowed')->nullable()->after('hand_raised_at');
            });
        }

        Schema::table('meetings', function (Blueprint $table) {
            $table->boolean('screen_sharing_locked')->default(false)->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('screen_sharing_locked');
        });

        foreach (['meeting_participants', 'meeting_join_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['hand_raised', 'hand_raised_at', 'screen_share_allowed']);
            });
        }
    }
};
