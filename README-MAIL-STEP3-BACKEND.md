# UIPE Mail — المرحلة 3 (الباك إند كامل)

انسخ الملفات فوق `uip-laravel-main` (نفس المسارات) ثم:

```
php artisan migrate
php artisan route:list --path=api/v1/mail     # للتأكد من الـ routes الجديدة
php artisan mail:process                      # تجربة يدوية للأمر
```
ولازم cron للسيرفر: `* * * * * php artisan schedule:run` (أو `schedule:work` في التطوير).
حتى لو الـ scheduler واقف: `GET /account` بيسلّم رسائل صندوق المستخدم المستحقة ويصحّي مؤجّلاته (تسليم كسول).

## الملفات
| الملف | الحالة |
|---|---|
| database/migrations/2027_11_20_100000_create_mail_advanced_tables.php | جديد |
| app/Services/Mail/MailboxService.php | معدّل |
| app/Services/Mail/MailFilterEngine.php | جديد |
| app/Services/Mail/MailSettingsService.php | جديد |
| app/Services/Mail/MailPortabilityService.php | جديد |
| app/Http/Controllers/Api/MailApiController.php | معدّل |
| app/Http/Controllers/Api/MailAdvancedController.php | جديد |
| app/Console/Commands/ProcessMailQueue.php | جديد (`mail:process`) |
| routes/mail.php | معدّل |
| routes/console.php | معدّل (سطرين schedule في آخره) |

## الـ API (كله تحت `/api/v1/mail`، وكلها بتقبل `?account_id=` أو هيدر `X-Mail-Account`)

**Snooze** — `POST /messages/bulk {ids|thread_ids, action:"snooze", until:"ISO"}` و `action:"unsnooze"`. المؤجّل بيختفي من الوارد، `folder=snoozed` بيعرضه، وبيرجع غير مقروء وقت `snoozed_until`. `folders.snoozed` في counts.

**Scheduled / Undo Send** — `POST /messages` (أو `/drafts/{id}/send`):
- من غير `schedule_at`: لو `settings.undo_seconds > 0` الرد `201` فيه `send_state:"undo"` و `scheduled_at` = نهاية المهلة، والرسالة في folder `outbox` (مش بتظهر في أي قائمة). الفرونت يعرض "تراجع" ثم ينادي `POST /messages/{id}/release` عند انتهاء المهلة، أو `POST /messages/{id}/cancel-send` للتراجع (بترجع مسودة). لو الفرونت اتقفل، الـ scheduler هيسلّمها.
- مع `schedule_at` (ISO، من 30 ثانية لسنة): `send_state:"scheduled"` و folder `scheduled`. `cancel-send` يرجّعها مسودة. لو التسليم فشل ترجع مسودة و `schedule_error`.
- `409` من cancel-send = اتسلّمت خلاص.

**Confidential** — في body الإرسال/المسودة: `confidential: {expires_days?, passcode?, keep_passcode?}` (`null` = إيقاف). للأعضاء الداخليين بس. المستلم يشوف `confidential.locked` = `passcode | expired | revoked | null`؛ لو مقفولة `body_*` = null ومفيش مرفقات. `POST /messages/{id}/unlock {passcode}` (30 دقيقة، 5 محاولات/5 دقايق)، `POST /messages/{id}/revoke {revoke?:bool}` للمرسل. التحويل (forward) ممنوع للمستلم، والرسالة مش بتتبحث ولا بتتصدّر عند المستلم.

**Settings** — `GET/PUT /settings {undo_seconds:0-30, sig_new_id, sig_reply_id}` (وبتيجي كمان جوه `GET /account`.settings مع `sig_new`/`sig_reply` جاهزين و`vacation_on`).

**Signatures** — `GET/POST /signatures {name, body_html}`، `PUT/DELETE /signatures/{id}` (حد أقصى 20).
**Templates** — `GET/POST /templates {name, subject?, body_html}`، `PUT/DELETE /templates/{id}` (حد أقصى 50).

**Filters** — `GET/POST /filters`، `PUT/PATCH/DELETE /filters/{id}`، `POST /filters/{id}/apply` (على آخر 500 في الوارد).
`{name?, conditions:{from,to,subject,has_words,not_words,has_attachment}, actions:{label_id,archive,mark_read,star,spam,trash}, is_active}` — الشروط AND ولازم شرط واحد على الأقل. `PATCH {is_active}` للتفعيل/الإيقاف. بتتطبّق وقت التسليم.

**Vacation** — `GET/PUT /vacation {enabled, subject, body_html, starts_at?, ends_at?}`. رد واحد لكل مرسل كل 4 أيام، ولا بيرد على رسائل تلقائية أو no-reply.

**Import / Export**
- `POST /import` (multipart: `file` = .mbox أو .eml حتى 100MB، `folder` = inbox|archive) → `{imported, skipped, failed}` (بيتجاهل المكرر بـ Message-ID؛ حد أقصى 5000 رسالة).
- `GET /export?format=mbox|json&folder=all|inbox|sent|archive|spam|trash` → تحميل ملف (mbox بالمرفقات، json من غير مرفقات). نزّله بـ fetch + Authorization → blob.

**تغييرات في الـ payload**: كل التواريخ ISO-8601. عناصر القوائم والرسائل فيها `send_state, scheduled_at, schedule_error, snoozed_until, confidential`، والرسالة فيها `is_auto, is_imported`. الرسائل المستوردة `from` بتاعها المرسل الخارجي الأصلي.

## تنبيه
مفيش PHP 8.4 / Composer شغّال في بيئتي، فمقدرتش أشغّل Laravel. اللي اتعمل: `php -l` على كل الملفات + اختبار منفصل لمنطق الفلاتر ومحلّل MIME (mbox/eml، base64/QP، RFC2231، عربي). الـ SQL والـ transactions اتراجعوا بالقراءة بس — جرّب migrate ثم Snooze/Scheduled/Undo/Import بإيدك قبل ما نبدأ الفرونت.
