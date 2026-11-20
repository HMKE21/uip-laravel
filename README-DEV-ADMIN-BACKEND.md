# إدارة المطورين — تحكم كامل (Backend)

## بعد فك الـ zip فوق المشروع
```
php artisan migrate        # 2027_11_25_100000_dev_access_control  (أعمدة جديدة + جدولين)
php artisan route:clear && php artisan config:clear
php artisan test --filter=DevAccessControlTest
```
لا يوجد تغيير في `.env`. الجداول القديمة متتمسحش.

## الفكرة: 3 طبقات بترتيب الأولوية
1. **المنصة** (أدمن المنصة من غير ما يختار جامعة) = سقف أعلى فوق كل الجامعات.
2. **الجامعة** = سياسة + قواعد (أدوار / إيميلات / دومينات).
3. **المطوّر نفسه** = سماح / حظر / طلب وصول + scopes وحدود خاصة بيه + تاريخ انتهاء.

قرار الوصول بالترتيب: تعطيل عام ← تعطيل المنصة ← تعطيل الجامعة ← حظر المطوّر ← الأدوار المسموحة ← قواعد المنع ← وضع `closed` (لازم قاعدة سماح) ← الموافقة المسبقة.
السماح اليدوي من الجامعة بيتخطى (الأدوار + قواعد منع الجامعة + closed + الموافقة) لكن **مش** سقف/قواعد المنصة (إلا لو السماح اتحط من أدمن المنصة).
الـ scopes = (استثناء المطوّر ← قاعدة سماح ← سياسة الجامعة ← الكل) ∩ سقف المنصة.

## السياسة الموسّعة `PUT /api/v1/dev-admin/policy`
`apps_enabled, webhooks_enabled, require_approval, sandbox_only, default_access (open|closed), max_apps_per_user, max_keys_per_user, max_webhooks_per_user, rate_limit_per_min, allowed_scopes, allowed_roles, allowed_events` (null = الكل).

## قواعد الإيميلات/الدومينات/الأدوار `/dev-admin/rules`
`kind`: `email` (بالظبط أو نمط `cs-*@x.edu.eg`) · `domain` (`x.edu.eg` أو `*.edu.eg` لكل الـ subdomains) · `role` (slug الدور)
`effect`: `allow` (ممكن معاها `scopes` و`max_apps` — يعني "الإيميلات دي مسموح لها بإيه بالظبط") أو `deny`. + `note` و`expires_at`.
اختبار بدون تعديل: `POST /dev-admin/policy/check {email, role?}` → بيرجّع هيتسمح له ولا لأ وليه.

## المطورين `/dev-admin/developers`
`GET` (q, role, status=allowed|blocked|pending|rejected|none, mode=developers|members) · `GET /{id}` (تفاصيل + تطبيقاته ومفاتيحه وwebhooks وسجله) ·
`PUT /{id}/access` (سماح/حظر + scopes + max_apps/keys/webhooks + webhooks_enabled + sandbox_only + rate_limit + expires_at + resume_apps) · `DELETE /{id}/access` (رجوع للسياسة) ·
`POST /{id}/decision {approve}` (موافقة/رفض طلب) · `POST /{id}/revoke-all {block?}` (إيقاف كل شيء فورًا) · `POST /bulk {user_ids, action: allow|block|reset}`.
المطوّر نفسه: `GET /api/v1/developer/access` و`POST /api/v1/developer/access/request {note}`.

## كمان
- تطبيقات: `GET/PUT/DELETE /dev-admin/apps/{id}` (تقييد scopes وحد الطلبات) · مفاتيح: `GET /dev-admin/keys` + `DELETE /keys/{id}` · Webhooks: `GET /dev-admin/webhooks` + `POST /webhooks/{id}/active`.
- `GET /dev-admin/meta` (الأدوار، الـ scopes، الأحداث، الأرقام).
- كل تعديل إداري بيتسجّل في سجل التكاملات (`kind = admin`).
- التطبيق الإداري بيتطبّق فعليًا على: إنشاء التطبيقات/المفاتيح/الـ webhooks، نداءات `/api/dev/v1`، OAuth، وإرسال أحداث الـ webhooks (حتى اللي كانوا شغالين قبل الحظر بيقفوا).
