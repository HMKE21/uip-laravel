# Meetings & Collaboration Platform — Deployment & Environment Guide

Round 10 (Admin & Docs) — بند 44. يغطي كل env vars الخاصة بموديول
الاجتماعات، إعداد WebSocket (Reverb)، STUN/TURN، وملاحظات أمان
للإنتاج. راجع أيضًا `docs/openapi/meetings.yaml` (بند 30) لتوثيق كل
endpoint.

> هذا الملف بيوثّق **إعدادات موديول الاجتماعات فقط**. متغيرات البيئة
> العامة للمشروع (`APP_KEY`, `DB_*`, `JWT_SECRET`, `MAIL_*`, إلخ) خارج
> نطاقه.

## 1. الطبقة المعمارية باختصار

- **لارافيل مش media server ولا SFU.** الاتصال الفعلي (صوت/صورة/شير
  شاشة) بيحصل مباشرة بين متصفحات المشاركين (WebRTC mesh، لحد
  `MEETING_MAX_MESH_PARTICIPANTS` مشارك — راجع `config/meetings.php`).
- **دور لارافيل**: (أ) توقيع الدخول لقناة الـ presence الخاصة بكل
  اجتماع عبر `POST /api/v1/meetings/{uuid}/signaling/auth` (بروتوكول
  Pusher، مش `/broadcasting/auth` القياسي — النظام كله بيستخدم JWT
  بيرر توكن يدوي مش Laravel Auth guard)، (ب) توفير قائمة STUN/TURN
  servers، (ج) REST APIs لكل حاجة مش real-time (CRUD، دعوات،
  تسجيلات، تحليلات...).
- تبادل SDP offer/answer وICE candidates نفسه، وكذلك "speaking
  detection" اللحظي، بيتم كـ **client events (whisper)** على نفس
  presence channel — من غير ما يعدي على سيرفر لارافيل أصلًا. قرار
  معماري متعمد لتقليل الحمل، مش نقص في التنفيذ.

## 2. متغيرات بيئة WebSocket (Laravel Reverb)

موديول الاجتماعات بيعتمد على `laravel/reverb` (broadcaster متوافق مع
بروتوكول Pusher، جزء من Laravel 11+). `config/broadcasting.php`
بيحوّل الـ default connection لـ `reverb` بدل `null`.

| المتغير | الوصف | مثال إنتاج |
|---|---|---|
| `BROADCAST_CONNECTION` | لازم تكون `reverb` في الإنتاج. القيمة `log` (المستخدمة في `phpunit.xml`) بتكتب البرودكاست في الـ log بدل سيرفر حقيقي — للاختبارات بس. | `reverb` |
| `REVERB_APP_ID` | معرّف تطبيق Reverb (أي string فريد). | `uip-meetings` |
| `REVERB_APP_KEY` | المفتاح العام اللي الفرونت (Echo/Pusher-JS) بيستخدمه للاتصال. | يتولّد عشوائي |
| `REVERB_APP_SECRET` | السر الخاص بتوقيع القنوات — **لا يتسرب للفرونت أبدًا**. | يتولّد عشوائي |
| `REVERB_HOST` | الدومين اللي سيرفر Reverb شغال عليه (لو خلف reverse proxy، ده الدومين العام مش `127.0.0.1`). | `ws.uip.example.com` |
| `REVERB_PORT` | البورت العام (443 خلف HTTPS/WSS عادة). | `443` |
| `REVERB_SCHEME` | `https` في الإنتاج (بيحدد `useTLS` تلقائيًا في `config/broadcasting.php`). | `https` |

### تشغيل سيرفر Reverb

```bash
php artisan reverb:start          # dev/foreground
php artisan reverb:start --host=0.0.0.0 --port=8080   # خلف reverse proxy
```

في الإنتاج، شغّله تحت process manager (systemd/Supervisor) وحط nginx
(أو مكافئه) كـ reverse proxy بيعمل WSS termination ويمرّر لـ
`REVERB_HOST:REVERB_PORT` محليًا. راجع الملاحظة داخل
`config/broadcasting.php` نفسه: قناة auth الخاصة بالموديول ده
**مش** مسجّلة في `routes/channels.php` ولا عبر `withBroadcasting()` —
التوقيع بيتم يدويًا داخل `MeetingsSignalingApiController::authorizeChannel()`
(`MeetingSignalingService::signChannel()`) عشان يدعم ضيوف بدون حساب
UIP (`uip.auth.optional` + `guest_token`) مش بس Laravel Auth guard.

## 3. متغيرات بيئة WebRTC (STUN/TURN)

`config/webrtc.php` — يغذّي `GET /api/v1/meetings/{uuid}/signaling/ice-servers`.

| المتغير | الوصف | Default | مثال إنتاج |
|---|---|---|---|
| `STUN_URLS` | قائمة STUN مفصولة بفواصل. عام، مفيش credentials. | `stun:stun.l.google.com:19302` | يفضل نفس القيمة أو STUN خاص |
| `TURN_URLS` | قائمة TURN مفصولة بفواصل. **فاضية = STUN بس** (كافي لمعظم الشبكات، لكن غير كافي لـ symmetric NAT/جدران حماية الشركات). | (فاضي) | `turn:turn.uip.example.com:3478` |
| `TURN_SECRET` | سر مشترك بين لارافيل وسيرفر TURN (مثلًا coturn) لتوليد credentials مؤقتة (ephemeral) بروتوكول `draft-uberti-behave-turn-rest`. لازم يتظبط لو `TURN_URLS` متظبطة. | (فاضي) | يتولّد عشوائي، نفسه في إعداد coturn |
| `TURN_CREDENTIAL_TTL_SECONDS` | صلاحية credential الـ TURN المؤقت بالثواني. | `600` | `600` |

⚠️ **TURN إلزامي عمليًا في الإنتاج** (بند 6 من المواصفة بينص عليه
صراحة) رغم إن الكود بيشتغل بدونه (STUN بس) — شبكات NAT المتماثلة
(symmetric) وجدران حماية الشركات مش هتقدر تعمل اتصال WebRTC مباشر من
غيره. لو مفيش سيرفر TURN جاهز، الخيارات الشائعة: تشغيل coturn
self-hosted، أو خدمة مُدارة (Twilio Network Traversal، Xirsys،...).

## 4. متغيرات بيئة عامة لموديول الاجتماعات

من `config/meetings.php` (كل قيمة عندها default معقول، مفيش حاجة
منها إلزامية للتشغيل الأولي — للـ tuning بس):

| المتغير | الوصف | Default |
|---|---|---|
| `MEETING_DEFAULT_DURATION_MINUTES` | المدة الافتراضية لو الـ host ماحددش. | `60` |
| `MEETING_MAX_DURATION_MINUTES` | أقصى مدة مسموحة. | `480` |
| `MEETING_MAX_MESH_PARTICIPANTS` | حد الـ WebRTC mesh (كل مشارك متصل بالكل) — أي عدد أكبر محتاج SFU حقيقي (خارج نطاق النسخة الحالية). | `8` |
| `MEETING_WAITING_ROOM_DEFAULT` | تفعيل غرفة الانتظار افتراضيًا لاجتماعات جديدة. | `true` |
| `MEETING_ALLOW_GUESTS_DEFAULT` | السماح بدخول ضيوف بدون حساب UIP افتراضيًا. | `false` |
| `MEETING_INVITATION_EXPIRY_HOURS` | صلاحية الدعوة قبل ما تتحول لـ `expired`. | `72` |
| `MEETING_GUEST_SESSION_TTL_MINUTES` | صلاحية توكن الضيف (JWT قصير الأجل، أطول من access token العادي عشان يغطي مدة الاجتماع). | `480` |
| `MEETING_REMINDER_MINUTES_BEFORE` | كام دقيقة قبل بداية الاجتماع يتبعت تذكير (بيقرأها أمر console منفصل، راجع القسم 6). | `10` |
| `MEETING_FRONTEND_JOIN_BASE_URL` | قاعدة رابط الانضمام العام. فاضية = الفرونت بيبني الرابط بنفسه من `join_token`. | (فاضي) |
| `MEETING_RECORDING_MAX_KB` | أقصى حجم لملف تسجيل واحد بعد الرفع (كيلوبايت). | `512000` (≈500MB) |

## 5. تخزين الملفات (تسجيلات + ملفات مشاركة)

الرفع (تسجيلات Round 9، ملفات مشاركة Round 7) بيتم عبر
`FileUploadService` اللي بيكتب مباشرة تحت `public_path()` (مش
Laravel `Storage` facade/disks) — نفس نمط باقي رفع الملفات في
المشروع. يعني:

- المجلد المستهدف لازم يكون **قابل للكتابة** من طرف PHP-FPM/web
  server user (`chown`/`chmod` على `public/uploads/...`).
- **لا علاقة له بـ `config/filesystems.php` disks** (`local`/`public`
  S3 إلخ) — لو محتاج تخزين سحابي (S3) للتسجيلات في المستقبل، محتاج
  تعديل حقيقي على `FileUploadService`، مش env var جديد بس.
- احسب مساحة تخزين كافية للتسجيلات تحديدًا: `MEETING_RECORDING_MAX_KB`
  × عدد التسجيلات المتوقعة. `MeetingAnalyticsService::platformMonitoring()`
  (بند 39) بيرجّع `recordings.storage_bytes` الفعلي — يستحق مراقبة
  دورية (مثلًا cron يبعت تنبيه لو قرب من حد القرص).

## 6. مهام Console/Scheduler مرتبطة

- **تذكيرات الاجتماع القادم** (بند 12): أمر console بيقرا
  `MEETING_REMINDER_MINUTES_BEFORE` (عبر `MeetingPolicyService`)
  ويحدد مين المفروض ياخد إشعار دلوقتي. لازم يتسجل في
  `routes/console.php`/`app/Console/Kernel.php` scheduler بتكرار
  مناسب (كل دقيقة مثلًا، عشان النافذة الزمنية دقيقة).
- تأكد إن الـ scheduler الرئيسي للمشروع (`php artisan schedule:run`
  عبر cron كل دقيقة) شغال أصلًا في الإنتاج — أمر التذكيرات ده معتمد
  عليه.

## 7. اعتبارات أمان للإنتاج

- **`REVERB_APP_SECRET` و `TURN_SECRET`**: أسرار سيرفر بحتة، متتخزنش
  ولا تتبعت للفرونت أبدًا (بيتوقّعوا بيهم توكنز/credentials مؤقتة
  بس). فرّق بينهم وبين `REVERB_APP_KEY` (عام، آمن يوصل للفرونت).
- **Audit & Security Logs (بند 38، Round 10)**: كل الأحداث الحساسة
  (قفل اجتماع، إزالة مشارك، فشل باسورد، فشل اتصال، بداية/نهاية
  تسجيل، بداية/نهاية مشاركة شاشة) بتتسجل في `audit_logs` — دي
  المصدر الوحيد لقسم "Security Events" في
  `GET /api/v1/admin/meetings/monitoring` (بند 39). تأكد إن جدول
  `audit_logs` عنده retention policy مناسبة (مفيش auto-purge حاليًا).
- **Admin Monitoring مقصود يكون عدادات بس** — بند 39 بينص صراحة إن
  الأدمن مش المفروض يشوف محتوى اجتماع خاص (شات/ملفات/تسجيل) تلقائيًا.
  لو أضفت endpoint أدمن جديد يلمس محتوى فعلي، راجع القرار ده الأول.
- **ضيوف بدون حساب (بند 23)**: `guest_token` بديل الـ JWT بيرر
  توكن العادي على كل مجموعة `meetings/{uuid}/*` (signaling/chat/
  files/notes/polls/recordings) عبر ميدلوير `uip.auth.optional` —
  مراجعة أي controller جديد في الموديول ده لازم تتأكد إنه بيستخدم
  نفس النمط (`resolveActorOr403()`/`resolveActor()`) مش
  `uip.auth` العادي، وإلا هيمنع الضيوف المقبولين من استخدامه.

## 8. Checklist تشغيل سريع (بيئة جديدة)

1. `composer install` (يحتاج `laravel/reverb` فعليًا مثبت لتشغيل
   `reverb:start` — راجع الملاحظة داخل `config/broadcasting.php`).
2. اضبط `REVERB_*` (القسم 2) وشغّل `php artisan reverb:start` تحت
   process manager.
3. اضبط `STUN_URLS`/`TURN_URLS`/`TURN_SECRET` (القسم 3) — TURN
   حقيقي مطلوب فعليًا في الإنتاج.
4. تأكد إن `public/uploads/...` قابل للكتابة (القسم 5).
5. سجّل أمر التذكيرات في الـ scheduler (القسم 6).
6. راجع `docs/openapi/meetings.yaml` لاستيراده في Postman/الـ API
   Developer Portal.
