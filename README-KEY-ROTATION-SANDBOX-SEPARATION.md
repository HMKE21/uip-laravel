# Rotate لمفتاح الـ API + فصل sandbox عن live (باك اند فقط)

انسخ الملفات فوق `uip-laravel-main` بنفس المسارات، وبعدين:

```
php artisan migrate
php artisan route:clear && php artisan config:clear
php artisan test --filter=DevModeSeparation
php artisan test --filter=WebhookSignature
php artisan test --filter=DevOpenApi
php artisan test --filter=DeveloperCenter
```

الـ migration (`2027_11_27_100000_dev_key_rotation.php`) بتضيف عمودين بس على `dev_api_keys` (`rotated_from_id`, `rotated_at`)، ومبتمسحش حاجة، وتقدر تشغّلها أكتر من مرة.

## 1) Rotate لمفتاح الـ API

`POST /api/v1/developer/keys/{id}/rotate` (نفس اعتماد باقي مسارات مفاتيح المطوّر، limit 10/دقيقة)

Body (كله اختياري):

| الحقل | المعنى |
|---|---|
| `grace_hours` | 0 → 168. الافتراضي 24. المفتاح القديم يفضل شغال المدة دي عشان تبدّل في الـ deployments من غير توقف. `0` = يبطل فورًا. |
| `expires_in_days` | 1 → 730. تاريخ انتهاء للمفتاح الجديد. لو متبعتش، والقديم ليه تاريخ انتهاء، الجديد بياخد نفس التاريخ. |

الرد (201):

```json
{ "key": { "id": 12, "key": "uipk_live_...(مرة واحدة بس)", "status": "active", "rotated_from_id": 11, ... },
  "old_key": { "id": 11, "status": "rotating", "expires_at": "...", "rotated_at": "..." },
  "grace_hours": 24,
  "dropped_scopes": [] }
```

- المفتاح الجديد بياخد نفس الاسم والـ mode والـ scopes والتطبيق والصندوق والـ IP allowlist.
- لو الجامعة شالت scope من المطوّر بعد ما المفتاح اتعمل، الـ scope ده مبيتنقلش وبيظهر في `dropped_scopes`.
- فترة السماح مبتطوّلش عمر مفتاح بيخلص قبلها (لو القديم هيخلص بعد ساعتين، بيفضل كده).
- أثناء السماح الجديد والقديم بيشتغلوا. القديم بيتبطّل لوحده لما `expires_at` يعدّي، وبيتسجّل `revoked_at` نهائيًا في `dev:maintenance --prune` (يوميًا 05:00) أو أول ما صاحبه يعمل/يـrotate مفتاح.
- الخانة: الـ rotate بفترة سماح محتاج خانة فاضية من حد المفاتيح (`max_keys_per_user`) لأن القديم لسه شغال. لو مفيش، الرد 403 برسالة واضحة، و`grace_hours = 0` بيشتغل دايمًا.
- مفتاح اتبدّل قبل كده → 422 (بدّل بتاعه الجديد). مفتاح ملغي أو منتهي → 422.
- كل مفتاح في القايمة بقى فيه `status`: `active | rotating | expired | revoked` + `rotated_from_id` + `rotated_at`.

## 2) فصل sandbox عن live

كان الفصل موزّع على الـ controller. دلوقتي في بوابة واحدة (`DevModeGate`) في `DevAuthMiddleware` قبل أي controller، ومصدر الحقيقة هو حقل `modes` في كتالوج `DevOpenApi` (`both | live | sandbox`):

- `live`: مفتاح sandbox بياخد `422 sandbox_unsupported` (labels، threads، تعديل/إرسال drafts، share-chat، meeting، save-drive، AI).
- `sandbox`: أي اعتماد مش sandbox بياخد `422 sandbox_only` (`/sandbox/*`).
- `both`: الـ controller بيوجّه لـ sandbox أو live حسب الـ mode.
- أي endpoint مش في الكتالوج بيتعامل كـ live-only لمفتاح sandbox (fail closed). وفي اختبار بيفشل لو أي route في `/dev/v1` معندوش `modes` صريح.

وكمان:

- `GET /webhooks` و`DELETE /webhooks/{id}` عبر الـ API بقوا بيشوفوا webhooks نفس الـ mode بس (webhook من mode تاني = 404). قبل كده مفتاح live كان يشوف ويمسح webhooks الـ sandbox والعكس. لوحة المطوّر (session) لسه بتعرض الاتنين.
- اعتماد sandbox مبيشيلش `mail_account_id` حقيقي أبدًا، حتى لو المفتاح اتربط بصندوق قبل كده. وإنشاء مفتاح sandbox بيتجاهل `mail_account_id`.
- تسليم الأحداث كان بيفلتر بالـ mode أصلًا (`DevEventBus`)، ومتغيّرش.
- التوثيق (`openapi.json`) بيذكر القيد في وصف كل endpoint، وكل operation فيه `x-uipe-mode` (`both | live | sandbox`) عشان الـ API Explorer في الفرونت يعرضه.

## الملفات
جديد: `DevModeGate.php`، migration الـ rotate، `tests/Feature/DevModeSeparationTest.php`.
معدّل: `DevAuthMiddleware`، `DeveloperAuthService`، `DeveloperAppService`، `DevOpenApi`، `WebhookService`، `DevApiController`، `DeveloperCenterController`، `DevCenterMaintenance`، `routes/developer.php`.
