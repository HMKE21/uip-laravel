<?php
/**
 * إعدادات موديول الاجتماعات (Round 1). نفس فلسفة config/upload.php/
 * config/messaging.php: قيم افتراضية ثابتة هنا + env override، وأي
 * قيمة عايزة تتغير من لوحة الأدمن وقت التشغيل (مش وقت الـ deploy) هتعدي
 * على MeetingPolicyService (اللي بيقرا من جدول `settings` العام أولًا
 * زي MessagingPolicyService بالظبط) مش من هنا مباشرة.
 *
 * max_mesh_participants=8: حد الـ mesh WebRTC (كل مشارك متصل بكل واحد
 * تاني) اللي وصفناه في خطة البناء — مش حد "الاجتماع" النهائي، ده حد
 * الـ Round 4 (WebRTC Core) قبل الترقية لـ SFU في Round 11 الاختياري.
 * مسموح يتغير عبر إعداد أدمن أعلى منه بس لو host عنده صلاحية vip/كبير
 * (Round 6 هيحدد فين بالظبط)، مش هنا.
 */

return [
    'default_duration_minutes' => (int) env('MEETING_DEFAULT_DURATION_MINUTES', 60),
    'max_duration_minutes'     => (int) env('MEETING_MAX_DURATION_MINUTES', 480),

    // حد الـ mesh WebRTC العملي (راجع docblock الملف). أي عدد أكبر من
    // كده محتاج SFU حقيقي (Round 11) — مش منطق بنفّذه هنا في Round 1،
    // بس القيمة نفسها متسجلة من دلوقتي عشان MeetingService::create()
    // يرفض max_participants أكبر منها لحد ما الـ SFU يبقى موجود.
    'max_mesh_participants' => (int) env('MEETING_MAX_MESH_PARTICIPANTS', 8),

    'waiting_room_default_enabled' => (bool) env('MEETING_WAITING_ROOM_DEFAULT', true),
    'allow_guests_default'         => (bool) env('MEETING_ALLOW_GUESTS_DEFAULT', false),

    // ساعات صلاحية دعوة قبل ما تتعتبر منتهية (invitation.status=expired) —
    // MeetingService::createInvitation() بيحسب expires_at من القيمة دي
    // لو الـ caller ما بعتش تاريخ صريح.
    'invitation_expiry_hours' => (int) env('MEETING_INVITATION_EXPIRY_HOURS', 72),

    // Round 2 (Lobby & Access, بند 23 — Guest Access). مدة صلاحية توكن
    // الضيف (JWT قصير الأجل بيتصدر من MeetingLobbyService::issueGuestToken()
    // بنفس UipJwtService::encode() اللي كل توكنات النظام بتتصدر بيها) —
    // الضيف مش عنده حساب UIP يجدد بيه، فمدة أطول من access token العادي
    // (900 ثانية) عشان تغطي مدة الاجتماع نفسه.
    'guest_session_ttl_minutes' => (int) env('MEETING_GUEST_SESSION_TTL_MINUTES', 480),

    // Round 3 (Signaling، بند 12 — Meeting Notifications: "Support
    // configurable reminders"). كام دقيقة قبل scheduled_start_at يتبعت
    // إشعار "starting soon" — أمر console منفصل (meetings:send-starting-
    // soon-reminders) هو اللي بيقرا القيمة دي (عبر MeetingPolicyService)
    // ويقرر مين المفروض ياخد إشعار دلوقتي، مش cron بيعدل الجدول مباشرة.
    'reminder_minutes_before' => (int) env('MEETING_REMINDER_MINUTES_BEFORE', 10),

    // القاعدة اللي رابط الانضمام العام بيتبني عليها في الـ API response
    // (join_url = base + '/' + join_token). فاضي افتراضيًا -> الفرونت
    // هو اللي بيبني الرابط الكامل بنفسه من الـ join_token لو الإعداد ده
    // مش متظبط، بدل ما نخترع دومين وهمي (نفس منطق site.php support_email).
    'frontend_join_base_url' => env('MEETING_FRONTEND_JOIN_BASE_URL', ''),

    // الإعدادات الافتراضية اللي بتترص جوه meetings.settings (JSON) وقت
    // الإنشاء لو الـ host ما حددش حاجة بديلة — قائمة مغلقة عمدًا (Round 1
    // بس)، أي مفتاح جديد (زي إعدادات الـ recording في Round 9) بيتضاف هنا
    // من غير ما يحتاج migration لأنه جوه عمود JSON واحد.
    'default_settings' => [
        'mute_on_entry'   => false,
        'camera_on_entry' => true,
        'allow_chat'      => true,
        // Round 7 (بند 19 — File Sharing). زي allow_chat بالظبط —
        // MeetingFileService::isFileSharingEnabled() بيقراها.
        'allow_file_sharing' => true,
        // Round 9 (بند 18 — Meeting Recording، إعداد "Recording
        // permissions" في قائمة إعدادات الاجتماع). زي allow_file_sharing
        // بالظبط — MeetingRecordingService::isRecordingEnabled() بيقراها.
        'recording_enabled' => true,
    ],

    // Round 9 (بند 18 — "Recording storage"). الحد الأقصى (كيلوبايت)
    // لملف تسجيل واحد بعد ما يوصل من المتصفح وقت stop() — أكبر بكتير من
    // max_size_kb العام (10MB افتراضيًا) لأن ده فيديو/صوت كامل مدة
    // الاجتماع، مش مستند. 500MB افتراضيًا (~ساعة فيديو 720p تقريبًا).
    'recording_max_kb' => (int) env('MEETING_RECORDING_MAX_KB', 512000),
];
