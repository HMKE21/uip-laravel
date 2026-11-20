# Replay Protection للـ Webhooks — الباك اند

فكّه فوق المشروع. **مفيش migration ومفيش تغيير إجباري في `.env`.**

```
php artisan route:clear && php artisan config:clear
php artisan test --filter=WebhookSignature
php artisan test --filter=DevOpenApi
php artisan test --filter=DeveloperCenter
```

## اللي اتضاف
- `app/Services/Developer/WebhookSignature.php` — المصدر الوحيد للتوقيع والتحقق. `verifySignature($payload, $header, $secrets, $tolerance = null)` بترجّع `true/false`، و`inspect()` بترجّع السبب كمان:
  `missing_header` · `malformed_header` · `missing_secret` · `signature_mismatch` · `timestamp_too_old` · `timestamp_in_future`.
  - التحقق بـ `hash_equals` (ثابت الزمن)، والنافذة **300 ثانية في الاتجاهين** (الرسالة القديمة أو المؤرّخة بالمستقبل مرفوضة).
  - التوقيع بيتفحص قبل الوقت، فمفيش سبب `timestamp_*` إلا لتوقيع صحيح.
  - بتقبل أكتر من secret وأكتر من `v1` في الهيدر (جاهزة لـ rotation من غير توقف).
  - `describe()` بترجّع الخوارزمية + test vector ثابت (للـ SDKs).
- `app/Services/Developer/WebhookReplayGuard.php` — بيحفظ `event id` لمدة ضعف النافذة (Cache::add ذرّية) عشان نفس الحدث ميتقبلش مرتين.
- `app/Services/Developer/WebhookSamples.php` — كود المستقبِل الجاهز (`verifySignature` + منع التكرار) بـ **PHP · Node.js · Python · Go**.
- `POST /api/v1/developer/webhooks/{id}/verify-signature` (JWT المنصة، مالك الـ webhook بس) — بيلصق الـ raw body والـ `X-UIPE-Signature` اللي وصله `{payload, signature, tolerance?}` ويرجّع `{valid, reason, message, timestamp, age_seconds, tolerance_seconds, event_id, replayed}`. الـ secret مبيسيبش السيرفر.
- `GET /api/dev/v1/docs/webhook-verification` — عام (زي openapi.json): الخوارزمية، الخطوات، النافذة، test vector، والأكواد الأربعة.
- `tests/Feature/WebhookSignatureTest.php` — بدون DB: حدود النافذة (±300/301)، التلاعب، rotation، الهيدرز البايظة، الحارس، والـ OpenAPI.

## اللي اتعدّل
- `WebhookService` — `sign()` بتفوّض للكلاس الجديد، والتسليم بيبعت `WebhookSignature::header()`، وفيه `checkSignature()`. التوقيع بيتحدد **وقت الإرسال الفعلي**، فتأخير الـ queue ومحاولات الـ retry كلها بتتوقّع بتوقيت جديد (والـ `X-UIPE-Event-Id` ثابت).
- `DevOpenApi` — وصف التحقق اتحدّث (النافذة من الـ config + نصيحة منع التكرار)، وكل webhook في الـ spec فيه `x-codeSamples` بالأربع لغات.
- `DevDocsController` · `DeveloperCenterController` · `routes/developer.php` · `config/developer.php` (`webhooks.tolerance`).

## الإعداد (اختياري)
`DEV_WEBHOOK_TOLERANCE=300` — النافذة بالثواني. الافتراضي 5 دقايق.
حارس الإعادة بيستعمل الـ cache الافتراضي (على السيرفر بدون Redis هيستعمل اللي في `CACHE_STORE`).

## ملاحظات
- أي مستقبِل لازم يقرأ الـ **raw body** قبل أي JSON parsing، وإلا التوقيع هيفشل.
- مفيش تغيير في شكل الهيدر `X-UIPE-Signature: t=...,v1=...`، فأي مستقبِل شغّال دلوقتي هيكمّل.
- لو عملت `php artisan developer:openapi --out=openapi.json` قبل كده، أعد التوليد (الوصف اتغيّر).
