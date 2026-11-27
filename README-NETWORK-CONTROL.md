# Network Control (بند 4 — بورتال الأمان) — الباك

فكّه فوق `uip-laravel-main` ثم: `php artisan migrate`

## الجديد
- **migration** `2028_03_12_100000_create_network_control.php`: جدولين `network_trusted_ips` و `network_rate_rules`.
- **قائمة الموثوقين (Allowlist "لا تحظر أبدًا")**: IP أو CIDR، بمدة اختيارية. بتتخطّى الحظر اليدوي/التلقائي، حظر الدولة، سياسة IP، حد المعدل العام وقواعد المعدل. وأي حظر جديد على IP موثوق بيتم رفضه.
- **حظر بنطاق CIDR** (IPv4 + IPv6) في `blocked_ips` نفسه، مع مدة (ينتهي تلقائيًا) — الفحص بيتجاهل المنتهي، والتنضيف كل ساعة (`network:purge-expired` في `routes/console.php`).
- **استيراد جماعي** (حتى 5000): سطر لكل IP/CIDR أو `ip,reason`، مع معاينة `dry_run` قبل الحفظ، وتخطّي الموثوق وIP الأدمن نفسه.
- **قواعد حد المعدل لكل مسار**: نمط مسار (`api/v1/auth/*`) + method + حد/دقيقة + نطاق (IP أو مستخدم) + إجراء (429 أو حظر الـ IP لمدة). بتتطبق في middleware جديد بعد `UipNetworkRestrictionMiddleware`. الحظر بيتجاهل العناوين الخاصة/المحلية.
- حظر الدولة وسياسة IP كانوا موجودين في Security Policies — الصفحة الجديدة بتعرضهم وبتسمح بحظر/فك دولة بسرعة (من نفس endpoint السياسة).

## الملفات
جديد: `database/migrations/2028_03_12_100000_create_network_control.php`, `app/Services/NetworkControlService.php`, `app/Http/Controllers/Api/SecurityNetworkApiController.php`, `app/Http/Middleware/UipNetworkRateRulesMiddleware.php`
معدّل: `app/Repositories/BlockedIpRepository.php`, `app/Http/Middleware/UipNetworkRestrictionMiddleware.php`, `app/Http/Middleware/UipRateLimitMiddleware.php`, `bootstrap/app.php` (سطر تسجيل الـ middleware), `routes/api.php` (مجموعة `security/network`), `routes/console.php` (جدولة التنضيف)

## API — `/api/v1/security/network`
`GET /overview` · `POST /blocks` · `POST /blocks/import` · `DELETE /blocks/{id}` · `GET /blocks/export` · `POST /trusted` · `DELETE /trusted/{id}` · `POST|PATCH|DELETE /rate-rules[/{id}]`
القراءة: أي موظف أمن. الكتابة: security_admin / admin (`write:security.policies.manage`).

## للتجربة
1. أضف IP/نطاق وانت جاي منه كموثوق، وجرّب تحظره → لازم يترفض.
2. حظر 15 دقيقة لعنوان تجريبي → يرجع 403 `ip_blocked`، وبعد المدة يتفك لوحده.
3. قاعدة `api/v1/auth/login` POST بحد 3/دقيقة → الرابعة ترجع 429 وعدّاد "تجاوزات اليوم" يزيد.
