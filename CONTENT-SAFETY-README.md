# Content Safety — منع المحتوى غير اللائق / الحسّاس في كل المنصة

## اللي اتغيّر (باك إند)
| الملف | الدور |
|---|---|
| `config/content_safety.php` | كل الإعدادات (المزوّد، العتبات، المسارات المستثناة…) |
| `app/Support/ContentLexicon.php` | قاموس عربي/إنجليزي/عربيزي + مطابِق بيمسك أساليب التحايل (فواصل بين الحروف، أرقام/رموز بدل حروف، تكرار الحروف، تشكيل وتطويل) من غير ما يغلط في الكلمات والأسماء العادية. القوائم نفسها محفوظة **مشفّرة** عمدًا (base64) فمفيش كلمة محظورة بتظهر صريحة في الكود |
| `app/Services/ContentSafetyService.php` | فحص النص + الصور + الفيديو (إطارات ffmpeg) + GIF + تسجيل المخالفات |
| `app/Http/Middleware/UipContentSafetyMiddleware.php` | **خط الدفاع المركزي**: أي POST/PUT/PATCH على `/api` (نصوص + ملفات + `data:image`) بيتفحص قبل أي controller — يعني رسائل، منشورات، تعليقات، بروفايل، مشاريع، ستوري، أسماء… |
| `bootstrap/app.php` | تسجيل الميدلوير |
| `app/Services/FileUploadService.php` / `ChunkedUploadService.php` | فحص الملفات برضه جوه الخدمات (الرفع بالأجزاء بيتفحص عند التجميع) |
| `app/Services/MessagingService.php` | بحث الـ GIF: قاموس + تصنيف Giphy إجباري (G/PG) + إعادة تحقق من Giphy وقت الإرسال (fail-closed) |
| `app/Services/ChatMediaPolicyService.php` | تصنيف `R` اتشال نهائيًا، والسقف الافتراضي `pg` |
| `app/Http/Controllers/Api/AdminContentSafetyApiController.php` + `routes/api.php` | API لوحة الأدمن |
| `database/migrations/2027_03_16_…_create_content_safety_events_table.php` | سجل المحاولات (مفيش نص المحتوى بيتخزّن) |
| `app/Console/Commands/ContentSafetyScan.php` | `php artisan content-safety:scan` — تقرير بالمحتوى القديم المخالف |
| `tests/Unit/ContentLexiconTest.php` | اختبارات القاموس |

## التفعيل (مرة واحدة)
1. `php artisan migrate`
2. ضيف في `.env` مفتاح فاحص الصور (**ده اللي بيفحص الصور/الفيديو/GIF نفسها**):
   - `CONTENT_SAFETY_OPENAI_KEY=sk-...` (OpenAI Moderation — مجاني، بيدعم الصور) **أو**
   - `SIGHTENGINE_API_USER` + `SIGHTENGINE_API_SECRET`
3. للفيديو لازم `ffmpeg` على السيرفر (بنفحص 4 إطارات).
4. `php artisan config:clear` ثم افتح **Admin → سلامة المحتوى** وتأكد إن "فاحص الصور والفيديو" شغّال.
5. `php artisan content-safety:scan --csv=storage/content-safety-report.csv` يطلّعلك المحتوى القديم المخالف (تقرير بس، من غير حذف تلقائي).

## ملاحظات مهمة
- من غير مفتاح فاحص الصور: النصوص، أسماء الملفات، نصوص SVG، وGIF الـ Giphy **متفلترة**، لكن محتوى الصورة المرفوعة نفسها **مش** بيتفحص (والأدمن بيشوف تحذير في الصفحة).
- الصور بتتبعت للمزوّد الخارجي للفحص (بتتصغّر الأول). كاميرا مراقبة الامتحان مستثناة عمدًا (خصوصية).
- القاموس بيتظبط من `.env`: `CONTENT_SAFETY_EXTRA_WORDS` للإضافة و`CONTENT_SAFETY_ALLOW_WORDS` للاستثناء، ومن صفحة الأدمن تقدر تختبر أي جملة.
- الطبقة الأخف (كلمات إيحائية وشتايم) بتتحجب في بحث/عناوين الـ GIF والأسماء بس، مش في الرسائل/المنشورات العادية، عشان النصوص الأكاديمية تفضل شغالة. الطبقة الصريحة بتتحجب في أي نص.
- مسارات مستثناة من فحص النص: المصادقة، إشارات المكالمات، إجابات الامتحانات، تشغيل الكود، والبلاغات (عشان المستخدم يقدر يبلّغ بنص صريح).

---

## تحديث: سد ثغرات ظهور محتوى غير لائق في الشات + إظهار سبب الحظر

**اللي كان بيحصل:** بحث GIF بمصطلح محظور كان بيرجّع صور غير لائقة، والمستخدم لما رسالته تتحجب كان بيشوف `content_not_allowed sexual` بدل السبب.

| المشكلة | الإصلاح |
|---|---|
| تصنيف Giphy (g/pg) بيحطّه اللي رفع الـ GIF → GIFs غير لائقة بتعدّي كـ "g" | `ContentSafetyService::scanRemoteStills()` بيفحص **الصورة نفسها** (لقطة ثابتة صغيرة) بفاحص الصور (OpenAI/Sightengine) بالتوازي، نتيجته متكاشة 30 يوم، والفشل = رفض. بيتطبّق على **نتايج البحث** وعلى **وقت الإرسال** (`assertGifVerified`) |
| أسماء ممثلين/مواقع غير لائقة مش في القاموس | `ContentLexicon`: مواقع جديدة (tier 1) + أسماء ممثلين وكلمات زي bikini (tier 2: بحث/عناوين الـ GIF والأسماء بس، مش الدردشة العادية) |
| نص رسالة +2000 حرف (حروف وأرقام ومسافات بس) كان بيعدّي من فحص الميدلوير كأنه base64 | `UipContentSafetyMiddleware`: الـ blob لازم يكون من غير مسافات |
| أي حظر جوه الـ controller (مثلًا `gif-message`) كان بيرجع رسالة عامة من غير كود | `ContentBlockedException::render()` + `MessagingController::handle()` بيرجّعوا `errors = {code:'content_not_allowed', category, kind, reason}` |
| الواجهة كانت بتعرض `content_not_allowed sexual` | `errorMessage()` + `blockedContentInfo()` في `client.js`، وفقاعة **"تم حظر الرسالة — السبب: …"** بدون Retry وبدون عرض المحتوى المخالف |
| لو طلب الـ GIF فشل، نتايج البحث القديمة كانت بتفضل ظاهرة | `ChatPicker`: نتايج مرتبطة بكلمة البحث (`itemsFor`) وبتتمسح عند الفشل + حارس خفيف على الجهاز (`lib/contentGuard.js`) |

### إعدادات جديدة (.env) — اختيارية
- `CONTENT_SAFETY_GIF_VERIFIED_ONLY=true` : نتايج GIF من قنوات Giphy الموثّقة بس (أشد أمانًا).
- **لازم** مفتاح فاحص الصور (`CONTENT_SAFETY_OPENAI_KEY` أو Sightengine) عشان فحص صور الـ GIF والصور المرفوعة يشتغل. من غيره الحماية بتبقى على النص/العنوان/التصنيف بس.
- بعد النشر: `php artisan config:clear` — وكاش البحث القديم بيتجاهل نفسه (مفتاح `gif5`).

## ffmpeg والفيديو (HEVC / فيديوهات الآيفون)

لو الفحص رجّع `could not read video frames — ffmpeg ... does not include a "hevc" decoder` يبقى الـ ffmpeg على السيرفر
(مثلًا `ffmpeg-free` بتاع Amazon Linux) مفيهوش decoder الـ H.265. الحل:

1. `sudo bash scripts/install-ffmpeg.sh` (بيركّب نسخة static كاملة في `/usr/local/bin`).
2. في `.env`: `FFMPEG_PATH=/usr/local/bin/ffmpeg` و `FFPROBE_PATH=/usr/local/bin/ffprobe` ثم `php artisan config:clear`.

حماية إضافية في الكود (شغّالة تلقائي):
- لو أول نسخة ffmpeg مش بتفك الكودك، بيجرّب `FFMPEG_FALLBACK_PATH` (افتراضي `/usr/local/bin/ffmpeg`) وأماكن الـ static builds الشائعة.
- لو كله فشل والمزوّد Sightengine، الفيديو (≤ دقيقة و≤ `CONTENT_SAFETY_VIDEO_API_MAX_MB`=50) بيتبعت لفاحص الفيديو عندهم. قفله بـ `CONTENT_SAFETY_VIDEO_API_FALLBACK=false`.
- صفحة الأدمن (`status`) بقت بترجّع `ffmpeg_path` و `ffmpeg_hevc`.
