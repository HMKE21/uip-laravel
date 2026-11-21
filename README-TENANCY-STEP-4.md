# Multi-tenancy — الخطوة 4: التحكم في الميزات (باك اند)

**القاعدة:** عميل جديد = الأساسيات بس (دخول، بروفايل، إشعارات، بحث...). أي ميزة تانية **مقفولة (403)** لحد ما:
العميل يطلبها ← أدمن المنصة يوافق ← تتفتح. (لو مفيش صف في الجدول = مقفولة؛ لو الجدول نفسه وقع = مقفولة برضو.)

## الملفات
| ملف | دوره |
|---|---|
| `config/tenant_features.php` | الكتالوج: `core` (دايمًا مفتوح) + 12 ميزة (messaging, meetings, feed, drive, mail, exams, academic, ai, analytics, security_portal, forms, guides, developer) + prefixes كل واحدة. الأطول بيكسب (`admin/meetings` ⟵ meetings رغم إن `admin` core). |
| `database/migrations/2027_12_11_100000_create_tenant_feature_grants.php` | جدول `tenant_feature_grants` على قاعدة المنصة الأم بس (بيتخطى نفسه على قواعد العملاء). |
| `app/Services/Tenancy/TenantFeatures.php` | التصنيف + طلب/قرار/إلغاء + حالة الميزات. |
| `app/Http/Middleware/TenantFeatureGate.php` | global بعد `ResolveTenant`: يرفض 403 `{errors:{code:"feature_not_enabled", feature:"meetings"}}`. على المنصة الأم مبيعملش حاجة. OPTIONS (preflight) و غير-/api بيعدّوا. |
| `app/Http/Middleware/UipTenantScopeMiddleware.php` | alias `uip.scope:tenant` / `uip.scope:platform`. |
| `Api/TenantFeatureController.php`, `Api/TenantFeatureAdminController.php`, `routes/tenant_features.php` | الـ API (تحت). |
| `Console/Commands/TenantFeaturesAudit.php`, `TenantFeature.php` | `tenant:features-audit` و `tenant:feature`. |
| `TenantCreate.php` (معدّل) | خيار `--features=meetings,mail` أو `all` (الافتراضي: ولا واحدة). |
| `bootstrap/app.php`, `routes/api.php` (معدّلين) | تسجيل الـ gate والـ alias، و`require` لملف الراوتس. |
| `tests/Feature/TenantFeatureGateTest.php` | 11 اختبار (sqlite). |

## الـ API
**جوه العميل** (`X-Tenant-Domain`، بادئة `tenant` = core فمش مقفولة):
- `GET  /api/v1/tenant/features` — أي مستخدم مسجّل: `{features:[{feature,label_ar,label_en,status,...}], enabled:[...]}`. status: `none|pending|enabled|rejected|disabled`. الفرونت يخبّي القوايم منها.
- `POST /api/v1/tenant/features/request` — أدمن العميل: `{features:["meetings","mail"], note?}`.
- `DELETE /api/v1/tenant/features/request/{feature}` — إلغاء طلب لسه pending.

**أدمن المنصة الأم** (من غير هيدر، `uip.admin`؛ أدمن العميل بياخد 404):
- `GET /api/v1/dev-admin/tenant-features/requests?status=pending|enabled|rejected|disabled|all` — صندوق الطلبات من كل العملاء.
- `GET /api/v1/dev-admin/tenants/{id}/features`
- `PUT /api/v1/dev-admin/tenants/{id}/features/{feature}` — `{status:"enabled|disabled|rejected|pending", note?}`
- `PUT /api/v1/dev-admin/tenants/{id}/features` — `{features:{messaging:"enabled",meetings:"disabled"}, note?}` (بيتحقق من الكل قبل ما يكتب).

## التشغيل
```bash
php artisan migrate                      # على المنصة الأم (بيعمل جدول tenant_feature_grants)
php artisan test --filter=TenantFeatureGateTest
php artisan tenant:features-audit --summary   # لازم يقول: كل المسارات متصنّفة
php artisan tenant:feature demo list
php artisan tenant:feature demo enable messaging meetings    # أو all
php artisan tenant:feature demo disable meetings --note="..."
```
تجربة بالـ curl (بعد تسجيل دخول أدمن العميل):
```bash
curl https://API/api/v1/meetings -H 'X-Tenant-Domain: demo.example.com' -H "Authorization: Bearer $T"   # 403 feature_not_enabled
php artisan tenant:feature demo enable meetings
# نفس الطلب => 200
```

## ملاحظات مهمة
- **`unmapped=deny`**: أي route جديد مش مذكور في الكتالوج بيترفض عند العملاء. بعد ما تضيف module جديد شغّل `tenant:features-audit` (وفيه `--strict` للـ CI).
- مفيش تأثير على المنصة الأم ولا على الـ tenants القديمة `mode=shared`.
- `GET dev/v1/tenant/branding` و`domain-check` في core (محتاجهم الفرونت/Caddy قبل أي ميزة).
- فصل ميزة عن صفحة واحدة جواها (مثلًا "أدلة" لوحدها) = تعديل prefixes في الكتالوج بس.
- الميزات عند العميل بتتقفل على مستوى الـ API فقط. لو ميزة مرتبطة بملفات/realtime/queue فعزلهم في الخطوة 5.
- مفيش إشعار تلقائي لأدمن المنصة عند طلب جديد: بيشوفه في `tenant-features/requests` (ممكن نضيف إشعار لو عايز).
