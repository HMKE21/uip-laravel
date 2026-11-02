<?php
/**
 * إعدادات موديول الاجتماعات — Round 4 (WebRTC Core): بند 6 ("STUN/TURN
 * infrastructure for NAT traversal")، بند 33 (Real-Time Architecture —
 * WebRTC).
 *
 * ⚠️ الاتصال الفعلي (الصوت/الصورة/screen sharing) بيحصل مباشرة بين
 * المتصفحات (RTCPeerConnection mesh — كل مشارك متصل بكل واحد تاني، حد
 * الـ mesh هو MeetingPolicyService::maxMeshParticipants() اللي اتحدد من
 * Round 1). لارافيل هنا **مش** media server ولا حتى signaling server
 * فعلي — دوره الوحيد إنه:
 *   (أ) يوقّع دخول presence channel (Round 3 — MeetingSignalingService)،
 *   (ب) يوفّر إعدادات STUN/TURN دي (endpoint جديد Round 4 —
 *       MeetingSignalingService::iceServers()).
 * تبادل SDP offer/answer وICE candidates نفسه بين كل زوج متصفحات بيتم
 * عبر **client events (whisper) على نفس presence channel** اللي Round 3
 * أسسه (Reverb/Pusher-protocol بيدعمها built-in من غير أي كود لارافيل
 * إضافي — أي client مصرح له بالقناة يقدر يـ whisper لأي client تاني
 * عليها من غير ما الرسالة تعدي على السيرفر أصلًا). نفس المنطق لـ
 * "Speaking detection" (بند 6) — بيتحسب على الفرونت من Web Audio API
 * ويتباع كـ client event كمان، مش REST call ولا DB write (تردد عالي
 * جدًا يفتح لو اتسجل في الداتابيز، وده أصلًا مجرد مؤشر UI لحظي مالوش
 * قيمة تاريخية تتخزن). القرار ده متعمد، مش حاجة ناقصة — راجع docblock
 * MeetingSignalingService لتفاصيل أكتر.
 *
 * TURN_SECRET: سر مشترك بين لارافيل وسيرفر TURN (لو استخدمنا coturn —
 * الأشهر مفتوح المصدر) بيسمح بتوليد اسم مستخدم/باسورد TURN **مؤقت**
 * (ephemeral) بدل ما نعطي الفرونت سر TURN الثابت نفسه (لو سربته أي حد
 * يقدر يستخدم سيرفر الـ TURN بتاعنا كـ open relay — استنزاف باندويدث).
 * البروتوكول القياسي (coturn REST API الموصوف في
 * draft-uberti-behave-turn-rest، والمعتمد فعليًا كمعيار صناعي): username
 * = "{unix_timestamp_انتهاء}:{اسم اختياري}"، credential =
 * base64(HMAC-SHA1(secret, username)). راجع
 * MeetingSignalingService::iceServers() للتنفيذ.
 *
 * لو TURN_URLS فاضي (زي بيئة التطوير هنا) — بيرجع STUN بس. ده كافي
 * لمعظم الشبكات (NAT العادي)، لكن شبكات symmetric NAT/corporate
 * firewalls محتاجة TURN فعلي في الإنتاج (بند 6 بيطلبه صراحة).
 */

return [
    // قائمة مفصولة بفواصل زي "stun:a.com:19302,stun:b.com:19302" —
    // مفيش credentials لسيرفرات STUN أصلًا (بروتوكول عام).
    'stun_urls' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STUN_URLS', 'stun:stun.l.google.com:19302'))
    ))),

    // نفس شكل stun_urls بالفوق — فاضية افتراضيًا (راجع docblock الملف).
    'turn_urls' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TURN_URLS', ''))
    ))),

    'turn_secret' => env('TURN_SECRET'),

    // مدة صلاحية الـ credential المؤقت (بالثواني) قبل ما الفرونت يحتاج
    // يطلب iceServers() تاني. قصيرة نسبيًا (10 دقايق افتراضيًا) — نفس
    // فلسفة access token العادي، مش guest_session_ttl_minutes الطويلة.
    'turn_credential_ttl_seconds' => (int) env('TURN_CREDENTIAL_TTL_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Realtime SFU (Serverless SFU) — نظام الفيديو للاجتماعات الكبيرة
    |--------------------------------------------------------------------------
    | الـ mesh (RTCPeerConnection بين كل زوج) يفضل الوضع الافتراضي للاجتماعات الصغيرة. لما الاجتماع يكبر (أو الشبكة تبقى ضعيفة)
    | MeetingTransportService بيحوّل الغرفة كلها لـ SFU تلقائيًا، ولو الـ SFU وقع بيرجع للـ mesh. المفتاح (app_secret) مابيسيبش
    | السيرفر أبدًا: الفرونت بيكلم لارافيل (MeetingsSfuApiController) وهو اللي بيكلم Cloudflare.
    | أنشئ التطبيق من: Cloudflare Dashboard ▸ Realtime ▸ SFU ▸ Create App.
    */
    'sfu' => [
        'enabled'    => (bool) env('MEETINGS_SFU_ENABLED', false),
        'app_id'     => env('CF_SFU_APP_ID'),
        'app_secret' => env('CF_SFU_APP_SECRET'),
        'base_url'   => rtrim((string) env('CF_SFU_BASE_URL', 'https://rtc.live.cloudflare.com/v1'), '/'),
        'timeout'    => (int) env('CF_SFU_TIMEOUT', 15),

        // أقل عدد حاضرين (شاملك) يخلّي الغرفة تتحول SFU. فوق maxMeshParticipants بتتحول غصب حتى لو الرقم ده أكبر.
        'min_participants' => (int) env('MEETINGS_SFU_MIN_PARTICIPANTS', 5),
        // 'auto' = القرار تلقائي | 'sfu' = دايمًا SFU (لو متاح) | 'mesh' = دايمًا mesh (SFU مقفول)
        'force'            => strtolower((string) env('MEETINGS_SFU_FORCE', 'auto')),
        // أقصى عدد فيديوهات بيسحبها المتصفح الواحد (الصوت دايمًا للكل). بيحمي جهاز المشاهد في الاجتماعات الكبيرة.
        'max_video_subscriptions' => (int) env('MEETINGS_SFU_MAX_VIDEO_SUBS', 25),
        // لو الـ SFU فشل عند ناس كتير يتقفل المدة دي (ثواني) والغرفة ترجع mesh.
        'block_seconds'    => (int) env('MEETINGS_SFU_BLOCK_SECONDS', 300),
        // أقل مدة بين تحويلين (ثواني) — يمنع الرجوع والتحويل المتكرر.
        'min_dwell_seconds' => (int) env('MEETINGS_SFU_MIN_DWELL', 30),
    ],
];
