# Rate Limit Dashboard — الباك (جرّبه قبل الفرونت)
فكّه فوق المشروع. مفيش migration ولا config جديد. 5 ملفات:
- `app/Services/Developer/DevUsageService.php` (جديد) — تجميع الاستهلاك
- `app/Services/Developer/DeveloperAuthService.php` (معدّل) — الـ constructor خد DevLogService + `noteThrottled()`
- `app/Http/Controllers/Api/DeveloperCenterController.php` (معدّل) — method `usage`
- `routes/developer.php` (معدّل) — سطر واحد: `GET /api/v1/developer/usage`
- `tests/Feature/DevUsageTest.php` (جديد)

## تغيير سلوك مهم
الـ 429 كانت بتتحجب من غير ما تتسجل في `dev_request_logs`. دلوقتي بتتسجل **صف واحد في الدقيقة لكل مفتاح/توكن** (Cache::add ذرّي، فالفلود ميكتبش في الـ DB). يعني `throttled` = عدد الدقايق اللي اتحجب فيها، مش عدد الطلبات المحجوبة بالظبط. لو عايز العدد الدقيق قولي ونضيف عدّاد.

## التجربة
php artisan test --filter=DevUsage
curl -H "Authorization: Bearer <JWT>" "https://<domain>/api/v1/developer/usage?range=24h"   # range: 1h | 24h | 7d

الرد: `limits`, `totals{requests,errors,throttled,avg_ms}`, `series[]` (1h=دقيقة، 24h=ساعة، 7d=يوم)، `keys[]` (`limit_per_min`, `used_now`, `pct_now` + تجميعات الفترة)، `apps[]`, `top_paths[]`.
- للتجربة: اضرب مفتاح بأكتر من حده في الدقيقة، وبعدها `throttled` للمفتاح لازم يبقى >= 1 و`used_now` = الحد.
- `errors` = 4xx/5xx من غير 429. السلسلة والتجميعات معمولة لـ MySQL و SQLite (الاتنين متجرّبين syntax بس، ما اتشغلوش على DB حقيقي هنا).
