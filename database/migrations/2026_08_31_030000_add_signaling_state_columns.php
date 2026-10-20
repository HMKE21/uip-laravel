<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند "UIP Meetings" — Round 3 (Signaling): بند 6 الجزء الخاص بالـ
 * Signaling (Presence, Join/Leave, mute/camera state, connection state)،
 * بند 12 (Meeting Notifications — عمود reminder dedup)، بند 33
 * (Real-Time Architecture).
 *
 * additive بالكامل زي كل migration قبلها في الموديول ده — أعمدة جديدة بس
 * (Schema::table()->…()->nullable()/default()، مش change()/renameColumn())،
 * فمفيش أي احتياج لـ doctrine/dbal (نفس القرار من migration Round 2).
 *
 * ليه نفس أعمدة الحالة اتحطت على meeting_participants **و**
 * meeting_join_requests سوا: بند 23 (Guest Access) في Round 2 قرر إن
 * الضيف المقبول مفيش صف موازي له في meeting_participants — صف
 * meeting_join_requests (status=admitted + user_id=null) هو نفسه سجل
 * حضوره. فأي حالة real-time (مايك/كاميرا/اتصال) لازم تتسجل في نفس
 * الجدول اللي بيمثل حضور العنصر ده فعليًا، عشان اليوزر المسجّل
 * والضيف يتعاملوا بنفس المنطق في MeetingSignalingService من غير if
 * منتشر في كل حتة.
 *
 * القيم دي "آخر حالة معروفة" بس (best-effort snapshot) — مصدر الحقيقة
 * اللحظي الفعلي هو الـ WebSocket channel نفسه (Reverb presence channel
 * member list)، مش الداتابيز. بنسجلها هنا عشان:
 *   (أ) أي REST call لاحق (زي GET participants في Round 6) يقدر يعرض
 *       آخر حالة معروفة من غير ما يحتاج يفتح اتصال WebSocket بنفسه.
 *   (ب) استرجاع الحالة بعد إعادة تحميل الصفحة (refresh) قبل ما الـ
 *       presence channel يرجع يتصل تاني.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['meeting_participants', 'meeting_join_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->boolean('mic_enabled')->default(false)->after('status');
                $table->boolean('camera_enabled')->default(false)->after('mic_enabled');
                $table->boolean('screen_sharing')->default(false)->after('camera_enabled');

                // connecting: صدر توكن/بيحاول يوصل الـ WebSocket لسه.
                // connected: presence channel بيأكد إنه متصل دلوقتي.
                // reconnecting: كان متصل وفصل مؤقتًا (بند 34 — Connection
                // Quality، لسه مش منفذ بالكامل هنا، بس الحالة محجوزة).
                // disconnected: القيمة الافتراضية قبل أي اتصال، وبعد leave.
                $table->enum('connection_state', ['connecting', 'connected', 'reconnecting', 'disconnected'])
                    ->default('disconnected')->after('screen_sharing');

                $table->timestamp('last_seen_at')->nullable()->after('connection_state');
            });
        }

        // بند 12 — "Meeting starting soon" reminder، منع تكرار نفس
        // الإشعار لنفس الاجتماع أكتر من مرة (الأمر المجدول بيفحص العمود
        // ده قبل ما يبعت).
        Schema::table('meetings', function (Blueprint $table) {
            $table->timestamp('starting_soon_reminder_sent_at')->nullable()->after('scheduled_start_at');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('starting_soon_reminder_sent_at');
        });

        foreach (['meeting_participants', 'meeting_join_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['mic_enabled', 'camera_enabled', 'screen_sharing', 'connection_state', 'last_seen_at']);
            });
        }
    }
};
