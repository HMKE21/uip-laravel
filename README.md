# UIPE Platform — الخطوة 1 كاملة + تنزيل الـ SDKs (باك + فرونت + اختبارات)

الـ zip ده بيضم كل التغييرات: Tenants/Workspaces وتنزيل الـ SDK كـ zip (تبويب SDKs). مفيش حاجة تانية محتاجة تتركّب معاه غير `SDK.zip` (الباك اند بتاع مولّد الـ SDK) اللي إنت ركّبته.
ملفات التنزيل الإضافية: `DevSdkDownloadController` · `SdkPackager` · `DevSdkDownloadTest` · `DevSdks.jsx` · `sdkTools.js`.

# تفاصيل Tenants

انسخ `laravel/` فوق مشروع لارافيل و`react/` فوق مشروع React بنفس المسارات، وبعدين `php artisan migrate`.
ما اتشغّلش أي حاجة: الـ syntax اتفحص (`php -l` و esbuild) والاختبارات مكتوبة بس مش متجرّبة.

## ملفات موجودة وبتتعدّل (ادمجها لو عدّلتها عندك)
- `routes/developer.php` · `app/Services/Developer/DeveloperAuthService.php` · `app/Services/Developer/DevLogService.php` (فلتر `tenant_id` للسجل بس)
- `src/pages/developer/DeveloperCenter.jsx` (تبويب Workspaces) · `src/styles/css/uipe-developer.css` (آخره بس)
- لو كنت نسخت zip الـ SDKs الأول: `DeveloperCenter.jsx` و`uipe-developer.css` و`routes/developer.php` فيهم تعديلاتها كمان.

## جديد
- باك: migration (`tenants`, `tenant_members`, `tenant_branding`, `tenant_domains` + `tenant_id` على dev_apps/keys/webhooks)، `config/tenancy.php`، `app/Services/Tenancy/*`، `Tenant{,Public,Admin}Controller`.
- سجل معزول: `GET /api/v1/developer/tenants/{id}/logs` (طلبات مفاتيح وتطبيقات الـ Workspace وأحداث إدارته بس).
- اختبارات: `DevTenantTest` (من غير DB) و`DevTenantFlowTest` (تدفق كامل على sqlite في الذاكرة).
- فرونت: تبويب **Workspaces** في Developer Center: قائمة وإنشاء، Overview، Branding، Members، Domains (تعليمات DNS + فحص)، Keys & apps (إنشاء مفتاح بيظهر مرة واحدة)، Logs، وحذف بتأكيد الـ slug.

## ضبط
`TENANT_CNAME_TARGET` · `TENANT_ASK_SECRET` (لـ Caddy on-demand TLS) · `TENANT_RESERVED_DOMAINS` · `TENANT_MAX_*`.
مميزات `white_label` و`custom_domain` بيفعّلها أدمن المنصة: `PUT /api/v1/dev-admin/tenants/{id}` بـ `{"features":["white_label","custom_domain"]}`. مفيش شاشة أدمن في الفرونت للخطوة دي.

## لسه مش متعمل
شاشة أدمن المنصة للـ Workspaces، رفع الشعار كملف (دلوقتي رابط https بس)، وإنشاء webhooks/تطبيقات من داخل الـ Workspace من الواجهة (الـ API موجود).
