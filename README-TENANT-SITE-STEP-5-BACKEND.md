# موقع العميل — الخطوة 5 (باك اند): صفحات + مدونة + SEO + خطوط + نموذج تواصل + دومين وSSL تلقائي

الفرونت لسه ما اتلمسش. ما اتجرّبش تشغيل (مفيش PHP عندي): راجعت الكود بالقراءة وفحصت توازن الأقواس بس. شغّل الاختبارات قبل أي حاجة.

## التشغيل
1. `php artisan migrate` (ملف واحد: `2028_01_05_100000_tenant_site_step5.php`).
2. `php artisan test --filter=DevTenantSiteStep5Test` ثم `--filter=DevTenantSiteTest` و`DevTenantTest` و`DevOpenApiTest` (لازم يفضلوا ناجحين).
3. لازم الـ scheduler شغّال (`schedule:run` كل دقيقة أو `schedule:work`) علشان التفعيل التلقائي للدومينات.

## 1) صفحات متعددة
مفاتيح جديدة في إعدادات الموقع (المسودة والمنشور): `pages` (حد أقصى 8) و`blog` و`posts` (حد أقصى 30).
- صفحة: `{id, slug, kind: custom|about|contact, enabled, show_in_nav, title{en,ar}, seo{title,description,share_image}, sections[]}`. الأقسام بنفس أنواع الصفحة الرئيسية (+ نوعين جداد: `team` و`gallery`).
- slug بيتصلّح تلقائي (a-z 0-9 -)، فريد، ومبيقبلش المحجوز (`login, blog, p, w, api, admin…`).
- مدونة: `blog {enabled, title, intro, show_in_nav}` والمقالات في `posts`: `{id, slug, date(Y-m-d), published, title, excerpt, body (حتى 4000 حرف لكل لغة), cover(https), author, seo}`. المقال الجديد مسودة (`published:false`) لحد ما العميل ينشره.
- مسارات الفرونت المتفق عليها: `/p/<slug>` · `/blog` · `/blog/<slug>` (تحت `/w/<slug>` أو دومين العميل).
- حد حجم الإعدادات اترفع لـ 256KB (`TENANT_SITE_MAX_BYTES`).
- `PUT /site/draft` بقى يقبل `seo, blog, pages, posts` كمان. `pages` و`posts` بيتبدلوا كاملين (القايمة كلها) مش دمج.

## 2) نموذج "تواصل" + صندوق الرسائل
- قسم `contact` ليه خاصية جديدة `form: true` بتفعّل النموذج.
- زائر: `POST /api/dev/v1/tenant/site/contact` `{slug|domain, name, email, subject?, message, website}` (`website` = honeypot سيبه فاضي). الحماية: لازم نموذج مفعّل في المنشور، حد يومي للموقع (200)، 5 رسائل/ساعة لكل IP (بنخزّن hash)، منع التكرار.
- المالك/الأدمن: `GET /tenants/{id}/site/messages?status=new|read|archived&page=` · `PUT /site/messages/{mid} {status}` · `DELETE /site/messages/{mid}`. و`GET /site` بيرجّع `messages.unread`.
- مفيش إيميل إشعار للمالك لسه (الرسائل في الصندوق بس). لو عايزه قولي.

## 3) SEO
- في الإعدادات: `seo {title(≤70), description(≤160), share_image(https), favicon, robots: index|noindex}`، ولكل صفحة ومقال `seo` خاص بيه.
- `GET /api/dev/v1/tenant/site/seo?slug=|domain=&path=/blog/x[&preview=token]` بيرجّع `title/description` (en + ar) و`image` و`canonical` و`robots` و`type` (`article` للمقال) و`found`. الأولوية: SEO الصفحة ← SEO الموقع ← اسم الموقع. المعاينة دايمًا `noindex`.
- `GET …/tenant/site/sitemap` (XML) و`GET …/tenant/site/robots` (نص). الـ canonical بيستخدم دومين العميل الأساسي لو active، وإلا `/w/<slug>`.
- مهم للفرونت: الكروت (واتساب/فيسبوك) بتقرا HTML من غير JS، فمحتاجين Edge function على Vercel تقرا `seo` وتحقنه في `<head>`. ده شغل الفرونت (الخطوة 5 فرونت).
- `site` العام بقى يرجّع كمان `canonical_base` و`contact_form`.

## 4) الخطوط
- 23 خط (فيهم 9 بيدعموا العربي) بـ allow-list. `catalog.font_catalog` بيرجّع `{key,label,group,arabic,family}` و`family` اسم Google Fonts (null = خط النظام).
- في `theme`: `font` (النص) · `font_heading` (العناوين، `same` = نفس النص) · `font_scale` (sm|md|lg|xl) · `heading_weight` (medium|semibold|bold|extrabold).

## 5) قوالب أكتر
- جداد: `startup` · `academy` · `elegant`. و`classic/bold/campus/business` بقى فيهم صفحتين جاهزتين (من نحن + تواصل). `academy` مفعّل فيها المدونة.

## 6) دومين بشهادة SSL تلقائي
- أمر جديد `tenant:domains-check` بيشتغل كل دقيقة: بيفحص DNS للدومينات pending/verified لوحده (كل دقيقتين أول ساعة ← 15 د ← ساعة ← 6 ساعات ← يوميًا بعد 14 يوم) ويفعّلها أول ما الـ DNS يظبط. مفيش زرار "تحقق" مطلوب (الزرار لسه شغّال).
- بعد التفعيل بيفتح اتصال TLS على `الدومين:443` (ده كمان بيحفّز Caddy on-demand يصدر الشهادة)، وبيسجّل الحالة في `ssl: {status: none|pending|active|failed, checked_at, expires_at, error}`، وبيعيد الفحص قبل انتهاء الشهادة بـ 14 يوم. `failed` بعد 30 دقيقة من التفعيل من غير شهادة.
- payload الدومين بقى فيه `ssl` و`auto_check` و`next_check_at`.
- env جديدة: `TENANT_DOMAIN_AUTO=true` · `TENANT_DOMAIN_AUTO_BATCH=50` · `TENANT_DOMAIN_SSL_PROBE=true` · `TENANT_DOMAIN_SSL_RENEW_DAYS=14` · `TENANT_DOMAIN_REQUIRE_TXT=true`.
- `TENANT_DOMAIN_REQUIRE_TXT=false` بيخلّي التوجيه الصح (CNAME لـ `TENANT_CNAME_TARGET` أو A على `TENANT_EDGE_IPS`) كفاية من غير TXT، فالعميل بيعمل خطوة DNS واحدة. ركّز: ده أقل أمانًا (حد ممكن يسبق صاحب الدومين لو الدومين شغّال على نفس الـ edge)، فسيبه `true` للإنتاج.
- شرط الشغل: `TENANT_ASK_SECRET` متضبط وCaddy `on_demand_tls` شاور على `/api/dev/v1/tenant/domain-check`.
- ما اتعملش: إعادة فحص الدومين الـ active لو الـ DNS اتشال بعد كده، وإشعار للمالك لما الدومين يتفعّل.

## الملفات
جديد: migration `2028_01_05_100000_tenant_site_step5.php` · `TenantSiteSeo` · `TenantSiteMessages` · `TenantDomainAutomation` · `TenantDomainsCheck` (command) · `tests/Feature/DevTenantSiteStep5Test.php`.
معدّل: `TenantSiteTemplates` · `TenantSiteSanitizer` · `TenantSiteService` · `TenantSiteController` · `TenantPublicController` · `TenantDomainService` (verify اتقسم لـ `verify` + `evaluate` من غير throttle) · `config/tenancy.php` · `routes/developer.php` · `routes/console.php`.
