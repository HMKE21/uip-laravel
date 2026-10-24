# Portal Guides — Backend (صفحات الدليل)

## التشغيل
```
php artisan migrate          # portal_guides* (5 جداول) + صلاحية guides.manage (للأدمن)
php artisan test --filter=PortalGuideDefaultsTest
```
مفيش seeder مطلوب: الدليل بيتزرع تلقائيًا أول ما حد يفتحه من `database/data/guides/<portal>.php`.
بعد ما تضيف محتوى افتراضي جديد في الملفات: `php artisan guides:sync` (بيضيف الجديد بس) — `--force` يرجّع نصوص الافتراضي، `--portal=admin` لبوابة واحدة.

## الفكرة
- 8 أدلة: `student, university, faculty, academic-staff, supervisor, data-analysis, security, admin` (دور → بوابة في `config/guides.php`؛ مدير الأمان وضابط الأمان بيشاركوا دليل `security`).
- كل دليل: أقسام (ابدأ من هنا / أريد أن… / مجموعات الـ sidebar / أسئلة شائعة) → عناصر (عنوان + ملخص + شرح + route + صلاحية + خطوات + نصائح)، عربي + إنجليزي.
- المستخدم العادي يشوف دليل بوابته بس. اللي معاه `guides.manage` (الأدمن دايمًا) يشوف ويعدّل أي بوابة.
- كل تعديل: `version` الدليل بيزيد (الفرونت يعرض «جديد»)، وبيتسجل revision قبل/بعد (فيه استرجاع)، وaudit log (`portal_guide.*`).
- النص plain بس (HTML بيتشال)، والـ route لازم داخلي يبدأ بـ `/`.
- عنصر مربوط بصلاحية المستخدم مش معاه ← `locked: true`.

## القراءة (uip.auth)
| Method | Path | |
|---|---|---|
| GET | `/api/v1/guides` | قائمة الأدلة (+ `has_new`, `item_count`) |
| GET | `/api/v1/guides/me` | دليل بوابتي |
| GET | `/api/v1/guides/me/search?q=` | بحث (حرفين فأكتر) |
| POST | `/api/v1/guides/me/seen` | يشيل علامة «جديد» |
| GET | `/api/v1/guides/{portal}` | بوابتي، أو أي بوابة لو `guides.manage`؛ `?include_hidden=1` = وضع التعديل |
| GET | `/api/v1/guides/{portal}/search?q=` | |
| POST | `/api/v1/guides/{portal}/seen` | |

## التعديل (uip.auth + uip.can:guides.manage) — `/api/v1/admin/guides`
| Method | Path | Body |
|---|---|---|
| PUT | `portal/{portal}` | title_ar/en, subtitle_*, intro_*, icon, is_published |
| POST | `portal/{portal}/sections` | key, kind(overview/group/tasks/faq/custom), title_*, summary_*, icon, audience_roles[] |
| POST | `portal/{portal}/sections/reorder` | ids[] |
| GET | `portal/{portal}/revisions?limit=` | |
| POST | `portal/{portal}/reset` | يرجّع الافتراضي (مبيمسحش اللي الأدمن أضافه) |
| PUT / DELETE | `sections/{id}` | |
| POST | `sections/{id}/items` | key, title_*, summary_*, body_*, route, permission, icon, steps[{ar,en}], tips[{ar,en}], audience_roles[] |
| POST | `sections/{id}/items/reorder` | ids[] |
| PUT / DELETE | `items/{id}` | أي حقول + `section_id` لنقل العنصر + `is_hidden` |
| POST | `revisions/{id}/restore` | |

الرد بنفس شكل باقي الـ API: `{success, message, data, errors, meta}`. أخطاء: 403 (بوابة مش بتاعتك) / 404 / 409 (key مكرر أو تعارض في الاسترجاع) / 422 (`errors.<field>`).

## ملاحظات
- كتابة عنوان بلغة واحدة كفاية: اللغة الناقصة بتتنسخ تلقائيًا.
- مسح عنصر/قسم افتراضي بيتسجل في `deleted_keys` فـ `guides:sync` ميرجّعوش؛ `reset` بيرجّعه.
- الفرونت (المرحلة الجاية): صفحة `/<portal>/guide` في كل بورتال + عنصر sidebar + وضع التعديل.
