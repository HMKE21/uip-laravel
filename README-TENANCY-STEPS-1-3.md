# Multi-tenancy (White-label) — الخطوات 1 إلى 3 (باك اند)

كل عميل = قاعدة بيانات خاصة. قاعدة UIPE الحالية تفضل "المنصة الأم" ومبتتغيرش.

## الملفات
- `app/Services/Tenancy/TenantContext.php` — يبدّل الـ default connection لقاعدة العميل، ويعزل الـ cache.
- `app/Services/Tenancy/TenantResolver.php` — يحدد العميل من الدومين (جدول `tenant_domains` الموجود).
- `app/Http/Middleware/ResolveTenant.php` — global، يقرأ هيدر `X-Tenant-Domain` (أو الـ Host).
- `app/Services/Tenancy/TenantProvisioner.php` + `tenant:create` + `tenant:migrate`.
- `UipJwtService` — سر توقيع + مفتاح تشفير مشتق لكل عميل + claim `tid`.
- `UipAuthMiddleware` / `TokenRevocationService` — مفتاح `acct:status` فيه tenant.
- Migration `2027_12_10_100000_add_isolation_to_tenants` — أعمدة `mode, db_name, db_status, provisioned_at`.
- `tests/Feature/TenantIsolationTest.php`.

## قبل التشغيل (مرة واحدة على السيرفر)
1. `php artisan migrate` (على قاعدة المنصة).
2. صلاحية إنشاء القواعد. الأأمن (بدل ALL على *.*):
```sql
CREATE USER 'uip_app'@'localhost' IDENTIFIED BY '...';  -- لو مش موجود
GRANT ALL PRIVILEGES ON `uip`.* TO 'uip_app'@'localhost';
GRANT ALL PRIVILEGES ON `uipt\_%`.* TO 'uip_app'@'localhost';
FLUSH PRIVILEGES;
```
(`uip` اسم قاعدة المنصة عندك). بالنمط ده يقدر ينشئ قواعد `uipt_*` بس. لو تحب أي مستخدم تاني للعملاء: `TENANCY_DB_USERNAME/PASSWORD`.
3. الفرونت (خطوة 6) هيبعت `X-Tenant-Domain`. لحد ما نعملها، جرّب بـ curl.

## تجربة
```bash
php artisan test --filter=TenantIsolationTest

php artisan tenant:create "شركة تجريبية" --domain=demo.example.com --admin-email=boss@demo.com
# يطبع باسورد الأدمن مرة واحدة

# دخول عند العميل (لازم الهيدر):
curl -X POST https://API/api/v1/auth/login -H 'X-Tenant-Domain: demo.example.com' \
  -H 'Content-Type: application/json' -d '{"email":"boss@demo.com","password":"..."}'
# نفس الإيميل من غير الهيدر => لازم يفشل (المنصة الأم)
# إيميل من UIPE مع الهيدر => لازم يفشل
```
بعد أي migration جديدة: `php artisan tenant:migrate`.

## قواعد السلوك
- هيدر بدومين مجهول/موقوف => 404/403 (مش بيرجع لقاعدة UIPE بالغلط). 503 لو القاعدة لسه بتتجهّز.
- من غير هيدر + Host عادي (دومين UIPE أو IP) => المنصة الأم زي ما هي. لو دومين الفرونت بتاع UIPE بيبعت الهيدر، حطه في `TENANT_PLATFORM_HOSTS`.
- الـ tenants القديمة (Developer API) `mode=shared` => من غير أي تغيير.
- `tenant:create` بيمسح القاعدة والصفوف لو فشل في النص عشان تعيده.
- الـ migrations بتتشغّل كلها على قاعدة العميل (جداول `tenants`/`dev_*` بتتعمل فاضية وملهاش استخدام عنده). الأدوار والصلاحيات بتتنسخ من المنصة بنفس الـ ids.

## ملاحظات مهمة
- لازم php-fpm / request-per-process (مش Octane من غير reset).
- لسه غير معزول (خطوة 5): الملفات المرفوعة، قنوات الـ realtime، الـ queue workers (محتاجة تلف على العملاء)، الأوامر المجدولة، الإيميلات.
- الـ cache المعزول: file/redis/database (prefix أو مسار)؛ كمان مفتاح التوكن/الحساب فيه tenant.
- جوجل/Keycloak: بيشتغلوا على قاعدة العميل تلقائيًا، لكن إعداداتهم (redirect/client) عمرها ما اتفحصت لكل عميل — هنراجعها في خطوة 3 المتممة قبل الإنتاج.
- متكتبش اختبار على MySQL هنا: اختبارات العزل على sqlite.
