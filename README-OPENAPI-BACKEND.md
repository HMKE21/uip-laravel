# OpenAPI — الباك اند

فكّه فوق المشروع. مفيش migration. ملاحظة: `routes/developer.php` هنا نسخة كاملة فيها مسارات App Review كمان، فاستبدله عادي.

## اللي اتضاف
- `GET /api/dev/v1/openapi.json` — OpenAPI 3.1، عام (من غير اعتماد)، متكاشّي 10 دقايق + ETag. متولّد من الـ routes الفعلية (اللي عليها `dev.auth[:scope]`)، فالمسار والـ method والـ scope المطلوب مصدرهم routes/developer.php مش نص مكتوب باليد.
- `app/Services/Developer/DevOpenApi.php` — الـ builder + `catalog()` (الوصف والـ schemas لكل endpoint). بيغطي: 26 endpoint للـ Developer API + `/oauth/token` و`/oauth/revoke` + `webhooks` (5 أحداث + شرح التحقق من التوقيع ونافذة الـ 5 دقايق) + securitySchemes (bearer, oauth2 بـ scopes المنصة, clientBasic) + أخطاء/هيدرز مشتركة (`errors.code`, X-RateLimit-*).
- `php artisan developer:openapi [--out=openapi.json] [--check=openapi.json] [--url=]` — يطبع/يكتب الـ spec. `--check` بيفشل لو الملف قديم أو في route من غير توثيق (للـ CI، وهنستخدمه في الـ SDKs).
- `tests/Feature/DevOpenApiTest.php` — حارس الانحراف: أي route جديد في `dev/v1` من غير مدخل في الكتالوج، أو مدخل ملوش route، أو operationId مكرر، أو scope مش موجود، أو path param ناقص، أو $ref مش موجود، أو أحداث webhooks مختلفة عن config → الاختبار يفشل.

## لما تضيف endpoint جديد
1. ضيف الـ route في routes/developer.php مع `dev.auth:scope`.
2. ضيف مدخله في `DevOpenApi::catalog()` (المفتاح `METHOD /path`).
3. `php artisan test --filter=DevOpenApi`.

## تجربة
php artisan test --filter=DevOpenApi
php artisan developer:openapi --out=openapi.json
curl -s https://<domain>/api/dev/v1/openapi.json | head
