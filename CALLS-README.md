# المكالمات الصوتية (1:1) — الباك إند

مكالمات داخل المحادثات المباشرة (مش الجروبات). الصوت بيعدي P2P بين المتصفحين (WebRTC)، ولارافيل signaling بس عن طريق
polling (الشات أصلًا polling، فمفيش websocket مطلوب). الفيديو متقفل بـ `CALLS_VIDEO_ENABLED=false` لحد المرحلة 2.

## التشغيل
1. `php artisan migrate` — بينشئ `calls` و `call_signals`.
2. **TURN (مهم)**: من غيره المكالمات هتفشل على أغلب شبكات الموبايل. أسهل مجاني: Cloudflare ▸ Realtime ▸ TURN ▸ Create key،
   وحط `CF_TURN_KEY_ID` + `CF_TURN_API_TOKEN` في الـ env. بدائل: `METERED_APP` + `METERED_API_KEY`، أو coturn (`TURN_URLS` + `TURN_SECRET`).
   من غير أي مفاتيح بيرجع STUN بس (يشتغل على الشبكات البسيطة). الـ credentials مؤقتة دايمًا والمفتاح الأصلي مبيوصلش للمتصفح.
3. (اختياري) `calls:prune` متجدول يوميًا — تنضيف تخزين بس؛ المكالمات العالقة بتتقفل كسولًا مع أي poll.

## الـ API (تحت `/api/v1/messaging`، نفس صلاحية `messaging.use`)
| Method | Path | الغرض |
|---|---|---|
| GET | `/calls/ice` | `ice_servers` + `video_enabled` + `ring_seconds` |
| GET | `/calls/poll?client_id=&call_id=&after=` | `{incoming[], call, signals[]}` + نبضة heartbeat لو `call_id` متبعت |
| POST | `/calls` | `{conversation_id, type?:'audio', client_id}` → المكالمة (ممكن `status:'busy'`) |
| POST | `/calls/{id}/accept` | `{client_id}` |
| POST | `/calls/{id}/decline` | — |
| POST | `/calls/{id}/end` | `{reason?: hangup\|failed\|connection_lost}` |
| POST | `/calls/{id}/signal` | `{client_id, kind: offer\|answer\|ice\|state, payload}` |

`client_id` = معرّف عشوائي بيتولّد لكل تبويب؛ بيمنع تبويبين لنفس المستخدم يردوا/يستقبلوا إشارات نفس المكالمة
(`call.handled_elsewhere=true` بيقفل الرنّة في التبويبات التانية).

## الحالات
`ringing → active → ended` | `declined` | `cancelled` (المتصل قفل قبل الرد) | `missed` (مفيش رد `CALLS_RING_SECONDS`=45) |
`busy` (المستلم في مكالمة) — ومكالمة شغالة طرفها مبعتش نبضة `CALLS_STALE_SECONDS`=25 تتقفل `connection_lost`.
كل انتقال بـ UPDATE مشروط، فسباق قبول/رفض/إنهاء مش بيكرر رسالة السجل ولا الإشعار.

## بيطلع في الشات
رسالة `message_type='call'` من المتصل، `metadata = {call_id, call_type, outcome: completed|missed|cancelled|declined|busy|failed, duration, caller_id, callee_id}`
و`body` نص إنجليزي احتياطي. معاينة الـ inbox بترجع `preview_type='call'` + `preview_meta` (نفس metadata). المكالمة الفايتة/الملغية/المشغولة
بتبعت إشعار `missed_call_message` للمستلم (لينك لنفس المحادثة).

## الخصوصية والحدود
مفيش مكالمة لو في حظر من أي طرف، أو الحساب محذوف، أو جروب. صورة الطرف التاني بتحترم إعداد إخفاء الصورة.
`/messaging/calls/*` ليه bucket rate-limit مستقل (`CALLS_RATE_LIMIT_PER_MIN`=300) عشان الـ polling السريع ميتحجبش على الـ 60/دقيقة العامة؛
بدء المكالمات نفسه محدود بـ `CALLS_START_PER_MINUTE`=8.
