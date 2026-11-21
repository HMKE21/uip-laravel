# الخطوة 2 (باك اند فقط) — المشاركين الخارجيين + Embed SDK

**الحالة: `php -l` عدّى، ومنطق الـ origins وصفحة الـ iframe اتجرّبوا مباشرة بـ PHP. اختبارات PHPUnit لسه ما اتشغلتش.** اتصلّح باج في EmbedFramePage (theme غير موجود في صفحة التوكن غير الصالح). الفرونت لسه ما اتبداش.

## تشغيل
1. فك الـ zip فوق المشروع (الملفات بنفس المسارات).
2. `php artisan migrate` (migration: `2027_12_03_100000_create_tenant_embed.php`).
3. `php artisan test --filter=DevTenantParticipantsTest` ثم `--filter=DevOpenApiTest` و`DevSdkTest` (الـ OpenAPI اتوسّع بـ 5 operations).

## اللي اتضاف
- جداول: `tenant_participants` (external_id + اسم + دور)، `tenant_embed_origins`، `tenant_embed_sessions` (hash بس).
- Scopes جديدة: `participants.manage`, `embed.sessions` (مفتاح tenant بس، live بس).
- Developer API: `GET/PUT/DELETE /participants[/{external_id}]`, `POST /embed/sessions`.
- عام (من غير مفتاح): `GET /embed/loader.js`, `GET /embed/frame`, `POST /embed/exchange`, `GET|DELETE /embed/session`.
- إدارة من الـ workspace: `/api/v1/developer/tenants/{id}/participants` و`/embed-origins`.
- `UipSecurityHeadersMiddleware`: صفحة الـ frame بس بتحط CSP/Permissions-Policy بتاعتها (من غير X-Frame-Options).

## تدفق الـ embed
1. سيرفر العميل: `POST /api/dev/v1/embed/sessions` بمفتاحه `{external_id, name, role?, room?, origin?}` ← `{token (ejt_…), embed_url, expires_at}` (افتراضي 120 ثانية، مرة واحدة).
2. المتصفح: `UIPE.meetings.embed({container, getToken})` من `…/api/dev/v1/embed/loader.js`.
3. الـ iframe بيبدّل التوكن بـ POST بتوكن جلسة `ems_…` (ساعتين) ومربوط بالـ origin، وبيبعت `ready` للصفحة الأم.
4. أمان: `frame-ancestors` = النطاقات المسموحة للـ tenant بس، postMessage لـ origin واحد متحقق منه، التوكن بيتحرق بـ UPDATE ذرّي.

## لسه ناقص
اختبارات الـ embed (`DevEmbedTest`) ما اتكتبتش، والـ media server (الخطوة 3).
