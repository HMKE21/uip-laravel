# Tenants — الخطوة 1 (جزئي: الباك اند، لسه من غير اختبارات ولا فرونت)

انسخ الملفات فوق مشروع لارافيل بنفس المسارات، وبعدين `php artisan migrate`.
الـ migration إضافي بس (جداول جديدة + عمود `tenant_id` nullable على `dev_apps` و`dev_api_keys` و`dev_webhooks`) ومش بيغيّر أي بيانات موجودة.
ملفين موجودين اتعدّلوا: `routes/developer.php` و`app/Services/Developer/DeveloperAuthService.php`. لو كنت عدّلتهم عندك دمج التعديل بإيدك.

## اللي اتعمل
- `tenants` مستقل (company / university / developer) + `tenant_members` (owner / admin / developer / viewer) + `tenant_branding` + `tenant_domains`.
- عزل: كل app/key/webhook بيتعمل جوه tenant بيتختم بـ `tenant_id`. مفتاح tenant مينفعش يتربط بتطبيق برّه نفس الـ tenant. والمفتاح بيقف فورًا لو الـ tenant اتوقف أو صاحب المفتاح اتشال أو بقى viewer (بوابة في `DeveloperAuthService`).
- لما عضو يتشال: مفاتيحه بتتلغي وتطبيقاته بتتوقف وwebhooks بتاعته بتتعطل.
- Branding: اسم، شعار وfavicon (https بس)، ألوان #RRGGBB، theme، لغة، روابط دعم وخصوصية وشروط. `hide_platform_branding` محتاج feature `white_label` (بيدّيها أدمن المنصة).
- دومين مخصص: TXT `_uipe-challenge.<domain>` = `uipe-verify=<token>` ثم CNAME اختياري لـ `TENANT_CNAME_TARGET`. الحالات pending → verified → active. محتاج feature `custom_domain`.
- Caddy on-demand TLS: `GET /api/dev/v1/tenant/domain-check?domain=…&token=…` (مقفولة لو `TENANT_ASK_SECRET` فاضي).

## الـ API
- العضو: `/api/v1/developer/tenants` (+ `/{id}`، `/branding`، `/members`، `/domains`، `/apps`، `/keys`، `/webhooks`).
- أدمن المنصة: `GET /api/v1/dev-admin/tenants` و`PUT /api/v1/dev-admin/tenants/{id}` (status, plan, features, limits).
- عام: `GET /api/dev/v1/tenant/branding[?domain=]`.
- الاتنين العامين مش بيظهروا في openapi.json.

## الـ env
`TENANT_CNAME_TARGET` · `TENANT_ASK_SECRET` · `TENANT_RESERVED_DOMAINS` (مفصولة بفاصلة) · `TENANT_MAX_OWNED/MEMBERS/DOMAINS/APPS/KEYS/WEBHOOKS`.

## مش متعمل لسه
اختبارات، لوحة الإدارة في الفرونت، وسجل طلبات معزول لكل tenant. وكمان الـ syntax اتفحص بـ `php -l` بس، ومفيش حاجة اتشغلت.
