# SDKs — الباك اند (الخطوة 4)

فكّه فوق المشروع. مفيش migration ومفيش تغيير في أي ملف موجود.

## اللي اتضاف
- `php artisan developer:sdk` — بيولّد 3 مكتبات + نسخة من الـ spec في `sdks/`:
  - `sdks/python` (stdlib بس، Python 3.8+) — `Client` + `verify_signature` / `construct_event` / `ReplayGuard`.
  - `sdks/js` (من غير dependencies، Node 18+) — `Client` + `verifySignature` / `constructEvent` / `ReplayGuard` + `index.d.ts`.
  - `sdks/php` (PHP 8.0+، ext-curl) — `\Uipe\Client` + `\Uipe\Webhooks::verifySignature` / `constructEvent` + `\Uipe\ReplayGuard`.
  - `sdks/openapi.json` — الـ spec اللي اتولّدت منه.
- ميثود لكل endpoint (26 في الـ Developer API) متولّدة من `openapi.json`: Python `list_messages`، JS/PHP `listMessages`. مسارات `/oauth/*` مش جوه الـ SDK (الـ SDK بيشتغل بـ API key أو access token جاهز).
- كل Client: Bearer auth، فك غلاف `{success,message,data,meta}` وبيرجّع `data`، `ApiError` فيه `status` و`code` (`errors.code`) و`retry_after`، إعادة محاولة لـ `429` (بعد `Retry-After`) ولـ `502/503/504` في GET/PUT/DELETE بس، backoff + jitter، وفوقها `request_raw` للهيدرز (`X-RateLimit-*`, `X-UIPE-Mode`).
- الـ webhooks في المكتبات الـ 3 بنفس قواعد `WebhookSignature` بالظبط: `t=<unix>,v1=<hex>` ، HMAC-SHA256 على `t.rawBody`، مقارنة ثابتة الزمن، أكتر من `v1` وأكتر من secret (rotation)، نافذة 300 ثانية في الاتجاهين، التوقيع قبل الوقت، والـ `ReplayGuard` بيرفض نفس الـ event id لمدة ضعف النافذة. كل مكتبة بتتختبر على نفس الـ test vector اللي في `WebhookSignature::describe()`.
- `php artisan developer:sdk --check` — يفشل (exit 1) لو `sdks/` مختلف عن اللي هيتولّد (ملف ناقص / مختلف / زيادة). مع `php artisan developer:openapi --check=sdks/openapi.json` هما اللي في الـ CI.
- `.github/workflows/sdks.yml` — job بيتأكد إن الـ spec والـ SDKs محدّثين، وبعدين بيشغّل اختبارات Python (3.8 و3.12) وNode (18 و22) وPHP (8.0 و8.4).
- `tests/Feature/DevSdkTest.php` — بدون DB: كل لغة لها ملفاتها، مفيش placeholder ناقص، كل operation موجود في اللغات الـ 3، الخرج ثابت، والـ vector مطابق للباك اند.
- القوالب: `resources/sdk/{python,js,php}/**/*.tpl` والمولّد: `app/Services/Developer/Sdk/SdkGenerator.php`.

## أول تشغيل
```
php artisan developer:sdk                      # بيكتب sdks/
php artisan developer:sdk --check              # لازم يقول: SDKs are up to date.
php artisan test --filter=DevSdk

(cd sdks/python && python -m unittest discover -s tests -v)
(cd sdks/js && npm test)
(cd sdks/php && php tests/run.php)
```
ملاحظات:
- الـ base URL الافتراضي جوه المكتبات = أول `servers` في الـ spec = `APP_URL` + `/api/dev/v1`. ولّد بـ `APP_URL` بتاع الإنتاج أو `--url=https://...`. نفس القيمة لازم تتحط في `APP_URL` بتاع الـ workflow.
- `--sdk-version=1.2.0` بيغيّر رقم الإصدار في الـ 3 packages. الافتراضي 1.0.0.
- `--lang=python,js` للغات معيّنة، و`--spec=openapi.json` لو عايز تولّد من ملف مش من الـ routes.
- PHP: الـ Webhooks في PHP بتتحقق من الـ raw body (`file_get_contents('php://input')`)، والـ `ReplayGuard` جوه الذاكرة بس — مع أكتر من worker استعمل Redis (`SET key 1 NX EX 600`) ومرّر object فيه `firstSeen()`.

## لما تضيف endpoint
1. route في `routes/developer.php` + مدخل في `DevOpenApi::catalog()` (زي ما كان).
2. `php artisan developer:openapi --out=sdks/openapi.json` ثم `php artisan developer:sdk`.
3. commit لـ `sdks/`. من غير كده الـ CI بيفشل في `--check`.

## ليه ملف `sdks/` مش جوه الـ zip
المولّد محتاج المشروع شغّال (routes + config) عشان يطلّع الـ spec، فبتشغّله عندك مرة واحدة. الـ zip فيه القوالب والمولّد والأمر والاختبار والـ workflow بس.
