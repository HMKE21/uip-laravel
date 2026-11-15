# Content Safety — منع المحتوى الإباحي / المخلّ / الحسّاس في كل المنصة

## اللي اتغيّر (باك إند)
| الملف | الدور |
|---|---|
| `config/content_safety.php` | كل الإعدادات (المزوّد، العتبات، المسارات المستثناة…) |
| `app/Support/ContentLexicon.php` | قاموس عربي/إنجليزي/عربيزي + مطابِق بيمسك التحايل (`p.o.r.n`, `p0rn`, `سـكـس`, `pornnn`…) من غير ما يغلط في `Essex` / `كسكس` / `خولة` |
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
- الطبقة الأخف (sex, nude, شتايم…) بتتحجب في بحث/عناوين الـ GIF والأسماء بس، مش في الرسائل/المنشورات العادية، عشان نصوص أكاديمية ("sex differences") متتكسرش. الطبقة الصريحة بتتحجب في أي نص.
- مسارات مستثناة من فحص النص: المصادقة، إشارات المكالمات، إجابات الامتحانات، تشغيل الكود، والبلاغات (عشان المستخدم يقدر يبلّغ بنص صريح).
