# Tenants — الخطوة 1 (باك + فرونت، مرحلة جزئية)
back/ فوق مشروع لارافيل، front/ فوق مشروع React بنفس المسارات. بعدين: php artisan migrate (migration جديد: tenant_id على dev_request_logs).

## الباك (إضافة على الـ partial القديم)
- عزل حقيقي: القوايم والعدّادات والـ endpoints الشخصية (apps/keys/webhooks) بقت tenant_id = null بس، ومفيش endpoint شخصي يلمس مورد tenant.
- tenant_id بيتختم جوه نفس الـ INSERT، ومفتاح tenant في الـ Developer API بيشتغل جوه الـ tenant بس (webhooks).
- سجل طلبات واستهلاك معزول: GET /api/v1/developer/tenants/{id}/logs و /usage، والسجل الشخصي مبيعرضش حاجة من الـ tenants. حذف الـ tenant بيمسح سجله.
- أدمن: GET /api/v1/dev-admin/tenants/{id}.
## الفرونت
- تاب «مساحات العمل» في Developer Center: إنشاء، نظرة عامة، Branding بمعاينة مباشرة، دومينات (تعليمات DNS + فحص)، أعضاء وأدوار، تطبيقات/مفاتيح/webhooks، نشاط.
- White Label: على دومين الـ tenant بيتطبّق اللوجو واللون والـ favicon والـ footer قبل أول رندر (src/lib/tenantBrand.js). متغيّر اختياري VITE_PLATFORM_HOSTS.
## لسه ناقص / مش متجرّب
- لوحة أدمن المنصة في الفرونت (الـ API جاهز).
- اختبارات. ولا حاجة اتشغّلت: الباك php -l بس، والفرونت ما اتعملش له build (مفيش node_modules هنا).
