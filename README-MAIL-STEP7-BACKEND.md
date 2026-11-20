# UIPE Mail — المرحلة 7 (Backend): الإدارة والأمان

الـ zip ده **كامل** (الدفعة الجزئية + الباقي)، فمش لازم تفك `uip-laravel-step7-partial.zip` قبله. فكّه فوق المشروع وبدّل الملفات.

## التشغيل
```
php artisan migrate                       # 2027_11_24_100000_create_mail_security_admin (6 جداول + أعمدة)
php artisan route:clear && php artisan config:clear && php artisan optimize:clear
php artisan test --filter=MailSecurityTest
```
`.env` (اختياري):
```
UIPE_MAIL_WEBHOOK_SECRET=<توكن طويل عشوائي>   # من غيره الـ webhook مقفول (404)
```
الـ scheduler لازم شغال (`schedule:run` كل دقيقة). اتضاف: `mail:security-scan` كل 5 دقايق، و`mail:retention` يوميًا 04:40، و`mail:process` (الموجود) بقى بيعيد محاولة الإرسال الخارجي ويسجّل heartbeat.

### ربط Brevo (bounces / delivered)
Brevo ← Transactional ← Settings ← Webhooks ← Add: الـ URL
`https://<الباك>/api/v1/mail/webhooks/brevo` + هيدر `X-Webhook-Token: <نفس السر>` (أو `?token=` في الـ URL لو الواجهة مش بتدعم هيدرز). الأحداث: delivered, hard bounce, soft bounce, blocked, spam/complaint, invalid.

## الـ endpoints — `/api/v1/mail-admin/security/*`
متاحة لـ **أدمن الجامعة** (جامعته بس) و**أدمن المنصة** (من غير `university_id` = المنصة كلها، ومعاه = نطاق جامعة). الكلية وباقي الأدوار = 403.

| Method | Path | الوظيفة |
|---|---|---|
| GET | `/overview` | ملخص: أحداث مفتوحة، bounces، حالة الـ queue، السياسة |
| GET | `/events?status&type&severity&page` | النشاط المشبوه |
| POST | `/events/{id}/resolve` `{status: resolved\|ignored, note}` | قفل حدث |
| GET | `/audit?action&account_id&actor_user_id&from&to&q&page` | سجل التدقيق |
| GET | `/audit/export` | CSV (حد 5000 صف، محمي من formula injection) |
| GET/POST/DELETE | `/ip-rules`، `/ip-rules/{id}` | قواعد IP — `{cidr, action: block\|allow, note, expires_at}` |
| GET/POST/DELETE | `/suppressions`، `/suppressions/{id}` | عناوين ممنوع الإرسال ليها |
| GET | `/bounces?type&q` | الـ bounces/الشكاوى |
| GET | `/queue` / `/queue/deliveries?status` | مراقبة الـ queue + الإرسال الخارجي |
| POST | `/queue/deliveries/{id}/retry` | إعادة محاولة تسليم فاشل |
| GET/PUT | `/policy` | حدود الإرسال + الاحتفاظ + الإيقاف التلقائي (محتاج `university_id` للأدمن) |
| GET | `/flagged-accounts` | صناديق تحت Legal hold أو موقوفة بالأمان |
| POST | `/accounts/{id}/legal-hold` `{on, reason}` | تجميد الحذف النهائي لصندوق (السبب إلزامي) |
| POST | `/accounts/{id}/reinstate` | فك إيقاف الأمان بعد المراجعة |
| POST | `/api/v1/mail/webhooks/brevo` | Webhook (عام + توكن سري) |

## السلوك
- **IP rules**: `mail.ip` middleware على `/api/v1/mail/*` بس. قواعد المنصة (block) على كل الصناديق؛ قواعد الجامعة (block/allow) على صناديقها المؤسسية بس (الصندوق الشخصي = قواعد المنصة بس). **مش متركّب على `/mail-admin`** عمدًا عشان أدمن الجامعة ميتقفلش عليه. الأدمن ممنوع يحظر IP بتاعه، وأول `allow` لازم يشمل IP بتاعه. رد الحظر 403 بـ `errors.code = mail_ip_blocked`.
- **حدود الإرسال**: 200 رسالة / 1000 مستلم / 100 خارجي في الساعة لكل صندوق (قابلة للتغيير لكل جامعة) → 429 + حدث `send_limit`. لو الجامعة فعّلت `sec_auto_suspend`: بعد 5 مخالفات في 24 ساعة الصندوق بيتوقف (`suspended_by=security`) والأدمن بيفكه من `reinstate`.
- **Suppression**: hard bounce أو شكوى = العنوان بيتضاف تلقائيًا ومبنبعتلوش تاني (على مستوى الجامعة، والصناديق الشخصية على مستوى المنصة).
- **Retention** (لكل جامعة): trash / spam / sent / all (الحد الأدنى: 1 / 1 / 7 / 30 يوم). المسودات والمجدول مبيتمسحوش، والصناديق تحت Legal hold بتتخطّى دايمًا. `php artisan mail:retention --dry` بيعدّ من غير مسح. بيتنضّف كمان: أحداث أمنية مقفولة (180 يوم)، سجل تسليم (90)، bounces (365)، audit (365).
- **Audit**: كل إجراء أدمن (نطاقات، سياسات، تعديل صندوق، provisioning، إيقاف جامعة، IP، suppression، legal hold، retention، تصدير) + حذف نهائي/تفريغ السلة + إرسال خارجي + bounces. **مفيش موضوع أو نص رسائل** في أي سجل.

## الملفات
جديد: `UipMailIpMiddleware`, `MailSecurityApiController`, `MailWebhookController`, أوامر `mail:security-scan` و`mail:retention`, `tests/Feature/MailSecurityTest.php`.
معدّل: `routes/mail.php`, `routes/console.php`, `bootstrap/app.php` (alias `mail.ip` — لو بدّلت الملف بتاعك دمّج السطر ده بس), `MailTenantApiController` (audit), `ProcessMailQueue`, `UipNetworkRestrictionMiddleware` و`UipDeviceRestrictionMiddleware` (استثناء مسار الـ webhook), `MailSecurityService/MailBounceService/MailRetentionService`, `config/mailbox.php`, + ملفات الدفعة الجزئية.

## ملاحظات مهمة
- **اتكتبت من غير ما تتشغّل**: مفيش PHP ولا vendor عندي، فاتفحصت بمراجعة يدوية وفحص أقواس بس — شغّل `MailSecurityTest` وقولي لو أي حاجة وقعت.
- اتصلّح عيب في الدفعة الجزئية: الإيقاف التلقائي كان بيعدّ أحداث `send_limit` من الجدول، لكن الأحداث بتتدمج (حدث مفتوح/ساعة) فالعدّاد عمره ما كان هيوصل 3. دلوقتي عدّاد 24 ساعة في الكاش (`mailbox.security.suspend_after_violations`، الافتراضي 5).
- اتصلّح parsing الـ tag: Brevo أحيانًا بيبعته كنص JSON.
- الـ IP rules مش بتتطبّق على Developer API (`/api/dev/*`) لسه — لو عايزها قولي.
- `login_bruteforce` بيتسجّل كحدث على مستوى المنصة (مش تابع لجامعة)، فبيظهر لأدمن المنصة بس.
