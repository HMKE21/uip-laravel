# Lexicon Hub sync — الباك (نسخة جزئية، لسه ما اتجرّبتش)
فكّه فوق المشروع (8 ملفات). مفيش migration.

## اللي اتأكدت منه
الكود اللي في README بتاع الـ Hub مكنش موجود في Laravel: مفيش `lexicon:sync` ولا `LexiconSync` ولا `CONTENT_SAFETY_REMOTE_*`، و`ContentLexicon::check` كان على القاموس المدمج بس.

## اللي اتعمل
- `app/Support/LexiconStore.php` (جديد) — النسخة المحلية + المطابِق: بيهاش الكلمات وعبارات 1-4 كلمات والدومينات (ومعاها الـ parent domains) بنفس قواعد build.py ويقارنها بالقايمة. مفيش نص بيطلع بره.
- `app/Services/LexiconSyncService.php` (جديد) — تنزيل الـ manifest، التحقق من توقيع HMAC وهاش كل ملف، رفض الإصدار الأقدم، رفض أي تحديث بيصغّر الدومينات (أو الكلمات العامة) لأقل من 50%، نشر ذرّي، وفي حالة فشل الرابطين بيكمّل بآخر نسخة محفوظة.
- `app/Console/Commands/LexiconSync.php` (جديد) — `php artisan lexicon:sync [--force] [--status]`
- `app/Support/ContentLexicon.php` (معدّل) — `check()` بينده على الـ store في الآخر، و`tokens()`/`variants()` بقوا public.
- `app/Services/ContentSafetyService.php` (معدّل) — سبب جديد `blocked_link`، و`status()` بترجّع `lexicon`.
- `config/content_safety.php` (معدّل) — قسم `remote`.
- `routes/console.php` (معدّل) — جدولة يومية 03:20 (بتشتغل بس لو `CONTENT_SAFETY_REMOTE_URLS` متظبط).
- `.env.example` (معدّل) — `CONTENT_SAFETY_REMOTE_URLS` و`CONTENT_SAFETY_REMOTE_KEY`.

## .env
```
CONTENT_SAFETY_REMOTE_URLS=https://<user>.github.io/<repo>,https://<project>.vercel.app
CONTENT_SAFETY_REMOTE_KEY=<نفس LEXICON_SIGN_KEY>
```
ثم `php artisan config:clear && php artisan lexicon:sync`

## لسه ناقص
- ما اتجرّبش (الكود بس عدّى `php -l`)، ولا اتكتبت اختبارات PHPUnit.
- الـ manifest اللي في SAFETY-main.zip من غير توقيع (اتبني من غير LEXICON_SIGN_KEY)، فالـ sync هيرفضه لحد ما يتعمل build بالمفتاح.
- ملف GitHub Actions اللي الـ README بتاع الـ Hub بيشاور عليه مش موجود في الـ zip.
