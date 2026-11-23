# الخطوة 5 — Adapter الـ SFU في Laravel + تحديث سيرفر mediasoup

## ليه الشكل ده؟
Cloudflare بيتكلم **SDP** (offer/answer)، وmediasoup بيتكلم **RTP parameters** (transports/produce/consume). ملهمش لغة مشتركة،
فالمتصفح لازم يستخدم `mediasoup-client` لما المزوّد = selfhosted (ده شغل الفرونت إند، بعد ما تأكد إن الباك إند شغال).
لارافيل بيفضل هو البوابة الوحيدة: بيتأكد إن الطالب جوه الاجتماع، وإن الـ producerId اللي بيسحبه تابع لمشارك تاني في نفس الاجتماع، وسر السيرفر مابيطلعش منه.

**المزوّد الافتراضي لسه Cloudflare**: من غير ما تغيّر الإعداد، مفيش أي سلوك اتغيّر.

## 1) سيرفر الـ SFU (على EC2)
```bash
cd ~/uipe-sfu
cp server.js server.js.bak && cp rooms.js rooms.js.bak
# انسخ server.js و rooms.js من فولدر sfu-server/ هنا (استبدلهم)
node --check server.js && node --check rooms.js
pm2 restart uipe-sfu
curl -s localhost:4443/health        # {"ok":true,"rooms":0,"peers":0,"workers":2}
```
الجديد: endpoint لقفل producer، endpoint لـ ICE restart، تنضيف تلقائي للـ peers (DTLS اتقفل / ICE فاضل disconnected 30 ثانية / ماحصلش connect خلال دقيقة)،
منع إنشاء router مرتين لو طلبين جم مع بعض، تحقق من الـ ids، حد أقصى للـ peers في الغرفة.

## 2) Laravel
انسخ فولدر `laravel/` فوق المشروع (نفس المسارات). أو `git apply step5.patch` لو مفيش تعديلات تانية على نفس الملفات.
`.env.example` فيه الإضافات؛ حطها في `.env` الفعلي:
```
SFU_SELFHOSTED_URL=http://<SFU_PRIVATE_OR_PUBLIC_IP>:4443
SFU_SELFHOSTED_SECRET=<نفس INTERNAL_API_SECRET من .env بتاع سيرفر الـ SFU>
TURN_URLS=turn:<SFU_PUBLIC_IP>:3478?transport=udp,turn:<SFU_PUBLIC_IP>:3478?transport=tcp
TURN_SECRET=<نفس static-auth-secret في /etc/turnserver.conf>
STUN_URLS=stun:<SFU_PUBLIC_IP>:3478
```
بعدها: `php artisan config:clear` (ومفيش migrations).

⚠️ **الـ URL**: لو سيرفر Laravel في نفس الـ VPC/region بتاع سيرفر الـ SFU استخدم الـ **private IP** (`172.31.23.154`) وعدّل قاعدة 4443 في الـ Security Group
لتبقى مصدرها الـ private IP أو الـ Security Group بتاع Laravel. لو هتعدّي على الإنترنت العام، الـ HTTP العادي بيكشف السر على الطريق، يبقى لازم HTTPS (nginx + دومين).

## 3) تجربة (من غير ما نغيّر حاجة على المستخدمين)
1. من لوحة الأدمن (Meetings Control ▸ Video): فعّل `sfu_enabled`، واختار `sfu_provider = selfhosted`، واحفظ.
2. اضغط **Test** بتاع الـ SFU: لازم يرجع `ok`. الأكواد: `missing_credentials` (الـ URL/السر فاضيين في .env)، `bad_secret` (السر مش متطابق)، `unreachable` (الشبكة/الـ Security Group).
3. `GET /api/v1/meetings/{uuid}/sfu/config` لازم يرجع `"provider":"selfhosted"` و`"enabled":true`.
4. ارجع `sfu_provider = cloudflare` لو عايز تقفل التجربة.

## الـ API الجديد (للفرونت إند لاحقًا) — تحت `meetings/{uuid}/sfu/ms/`
| Method + path | Body | Response |
|---|---|---|
| POST `session` | — | `session_id, router_rtp_capabilities, send_transport, recv_transport, ice_servers, max_video_subscriptions` |
| POST `sessions/{id}/transports/{t}/connect` | `dtlsParameters` | `{connected}` |
| POST `sessions/{id}/transports/{t}/restart-ice` | — | `{iceParameters}` |
| POST `sessions/{id}/produce` | `transportId, kind, rtpParameters, source?` | `{id}` |
| POST `sessions/{id}/consume` | `transportId, producerId, rtpCapabilities` | `{id, producerId, kind, type, rtpParameters, producerPaused}` |
| POST `sessions/{id}/consumers/{c}/resume` | — | `{resumed}` |
| POST `sessions/{id}/producers/{p}/close` | — | OK |
المشتركين مع Cloudflare: `sfu/config` (فيه `provider` دلوقتي)، `sfu/peers` (`trackName` = producerId، وفيه `source`)، `sfu/report`، `sfu/leave` (بيحرّر الـ peer على السيرفر كمان).

## مش جوه الخطوة دي (متعمّد)
- التبديل التلقائي بين Cloudflare وmediasoup لو أحدهم وقع → الخطوة 6.
- حد `max_video_subscriptions` بيتطبق عند العميل (زي Cloudflare)؛ لارافيل بيبعته في `session` بس.
- ماتختبرتش على سيرفر حقيقي (مفيش PHP عندي)؛ الـ JS اتفحص syntax بس، والـ PHP اتراجع بالعين.
