# موقع العميل — الخطوة 1 (باك اند): رابط جاهز + قوالب + تخصيص + معاينة + نشر

مفيش حاجة في الفرونت لسه. الفرونت (الخطوة 2) هيبقى: مسار `/w/:slug` + صفحة الـ Site Builder في تاب جديد جوه المساحة.

## 1) رابط جاهز من عندنا (من غير دومين)
- لكل عميل رابط: `https://uip-nub.vercel.app/w/<slug>` وصفحة دخوله `/w/<slug>/login`.
- الـ slug من 3 لـ 40 حرف (`a-z 0-9 -`) **ويقبل أرقام بس** (`/w/1001`). العميل أو الأدمن يغيّره: `PUT /tenants/{id}/slug` `{ "slug": "1001" }`. القديم بيقف.
- الفرونت بيبعت هيدر `X-Tenant-Slug: <slug>` (زي `X-Tenant-Domain` بالظبط). `ResolveTenant` بيحوّل لقاعدة العميل. الدومين بيغلب لو الاتنين اتبعتوا.
- روابط الإيميلات: لو العميل مالوش دومين active بتروح على `/w/<slug>` بدل رابط المنصة.
- **تنبيه عزل (مهم):** الرابط ده على نفس origin المنصة (كوكيز وlocalStorage مشتركين). مناسب للتجربة والبداية. للإنتاج الحقيقي: دومين العميل، أو subdomain لما تشتري دومين وتعمل wildcard (مينفعش على `*.vercel.app`).
- env: `TENANT_ADDRESS_BASE=https://uip-nub.vercel.app` (فاضي = `app.frontend_url`)، و`TENANT_ADDRESS_ENABLED=true`.

## 2) القوالب
- جاهزة (في الكود): `classic` · `minimal` · `bold` (داكن) · `campus` (جامعة) · `business` (شركات).
- بتاعة العميل: يحفظ مسودته كقالب (`POST /site/templates`) ويطبّقه بعدين. حد أقصى 20.
- تطبيق قالب بيغيّر **المسودة بس**؛ المنشور مبيتغيرش لحد ما ينشر. ألوان هوية العميل (لو مضبوطة) بتغلب ألوان القالب.

## 3) التخصيص (كله من مخطط محدد، مفيش HTML/CSS/JS حر)
`theme` (ألوان، خط، radius، mode، density) · `layout` (شكل الدخول split/centered/card، nav top/side، فوتر) · `texts` · `nav` · `footer` · `home.sections` (hero, features, stats, steps, cta, text, faq, contact, logos) بالترتيب اللي العميل يحدده وبتفعيل/تعطيل لكل قسم. نصوص ثنائية `{en, ar}`. روابط https أو مسار داخلي بس. صور https بس.

## 4) المعاينة والنشر
- المسودة بتتحفظ بـ `PUT /site/draft` (تعديل جزئي).
- معاينة: `POST /site/preview-token` بيرجّع رابط `/w/<slug>?preview=<token>` (بيعرض المسودة لأي حد معاه الرابط، للقراءة بس؛ `rotate:true` بيبطل القديم).
- `POST /site/publish` بينقل المسودة للمنشور، بيحفظ النسخة القديمة (آخر 10)، وبيزامن اللون الأساسي/الثانوي مع هوية العميل (الإيميلات).
- `POST /site/discard` يرجّع المسودة للمنشور · `GET /site/versions` + `POST /site/versions/{id}/restore` (بيحط النسخة في المسودة، النشر قرار منفصل).
- مفيش نشر لسه => `site: null` والفرونت يعرض الشكل الافتراضي بهوية العميل.

## الـ API
مدير (جلسة Developer Center، تحت `/tenants/{id}`): `GET /site` (كل حاجة في request واحد: مسودة+منشور+قوالب+catalog+الرابط+المعاينة) · `PUT /site/draft` · `POST /site/apply-template {key|template_id}` · `POST /site/discard` · `POST /site/publish` · `GET /site/versions` · `POST /site/versions/{id}/restore` · `POST /site/preview-token` · `GET|POST /site/templates` · `DELETE /site/templates/{id}` · `PUT /slug`.
الصلاحيات: القراءة لأي عضو، التعديل owner/admin (ability جديدة `site`).
عام (من غير اعتماد): `GET /api/dev/v1/tenant/site?slug=…` أو `?domain=…` (`&preview=<token>` للمسودة).

## الملفات
جديد: migration `2027_12_20_100000_create_tenant_sites.php`، `TenantSiteTemplates`، `TenantSiteSanitizer`، `TenantSiteService`، `TenantAddress`، `TenantSiteController`، `tests/Feature/DevTenantSiteTest.php`.
معدّل: `config/tenancy.php`، `routes/developer.php`، `ResolveTenant`، `UipCorsMiddleware` (السماح بـ `X-Tenant-Slug`)، `TenantResolver` (`lookupSlug`)، `TenantService` (ability `site`)، `TenantMailBrand`، `TenantPublicController`.

## التشغيل والتجربة
1. `php artisan migrate` ثم `php artisan test --filter=DevTenantSiteTest` (واتأكد إن `DevTenantTest` و`DevOpenApiTest` لسه ناجحين).
2. `curl -X PUT .../tenants/ID/slug -d '{"slug":"1001"}'` ثم `POST /site/apply-template {"key":"campus"}` ثم `POST /site/publish`.
3. `curl 'https://<api>/api/dev/v1/tenant/site?slug=1001'` لازم يرجّع الإعدادات المنشورة. وبعدها عدّل المسودة ومتنشرش: نفس الطلب يفضل بالمنشور القديم، و`&preview=<token>` يرجّع المسودة.
4. `curl -H 'X-Tenant-Slug: nope' .../api/...` لازم 404 `unknown_workspace`.

## ملاحظات
- ما اتجرّبش تشغيل (مفيش PHP هنا): راجعت الكود بالقراءة بس.
- نقطة `tenant/site` بتقرا من قاعدة المنصة الأم صراحةً (`runCentral`) حتى لو الطلب جوه عميل. لو `tenant/branding` القديمة رجّعت 404 على دومين عميل isolated، نفس الإصلاح (تلفها في `runCentral`) — بلّغني.
- توكن المعاينة بيتخزّن كما هو (محتوى المسودة مش سر خطير). لو عايزه مشفّر قولي.
