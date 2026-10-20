# نظام Reports + Report Files — مقفول ✅

بند من قايمة الفجوات المعلّقة، مش من الـ 26 بند الأصليين — بورتالات University/
Admin كلهم عندهم صفحة Reports حقيقية دلوقتي، مش
placeholder.

## اللي اتنقل (منقول 1:1 من app/Services|Repositories|Controllers|Models
القديمة، بتحويل Database الخام -> DB facade / Core\Model -> Eloquent فقط،
من غير أي منطق مُخترع):

| ملف جديد | من القديمة |
|---|---|
| `app/Models/Report.php` | `app/Models/Report.php` |
| `app/Models/ReportSchedule.php` | `app/Models/ReportSchedule.php` |
| `app/Repositories/ReportRepository.php` | `app/Repositories/ReportRepository.php` |
| `app/Repositories/ReportScheduleRepository.php` | `app/Repositories/ReportScheduleRepository.php` |
| `app/Helpers/ReportExportWriter.php` | نفسها حرفيًا (namespace `App\Helpers` بالفعل، مفيهاش أي framework coupling — csv/json/xlsx/pdf/docx/xml/pptx) |
| `app/Services/ReportService.php` | `app/Services/ReportService.php` (635 سطر) — بس الأنواع اللي وراها بيانات حقيقية *متاحة فعلًا* في اللارافيل دلوقتي (شوف "مش منقول" تحت) |
| `app/Services/ReportSchedulerService.php` | `app/Services/ReportSchedulerService.php` |
| `app/Http/Controllers/Api/ReportsApiController.php` | `app/Controllers/Api/ReportsApiController.php` — سطح University + فرع admin |
| `app/Http/Controllers/Api/AdminReportsApiController.php` | `app/Controllers/Api/AdminReportsApiController.php` — سطح admin منفصل، كل فعل بيتسجّل audit log |

## Dependencies كانت ناقصة وكملتها (مش موجودة في اللارافيل قبل كده،
الميثودز دي موجودة بالظبط في القديمة، اتنقلت هنا لأول مرة عشان بند الـ
Reports محتاجها):

- **`app/Repositories/AuditLogRepository.php`** — كلاس جديد كامل، `recent()`
  بس (القديمة فيها `searchAdvanced()` كمان — هتيجي مع بند 25 الأمن).
  `AuditLogService` (بند 3) كانت بتكتب بس، مفيهاش قراءة.
- **`UniversityRepository::allWithStats()`** — مضافة (كانت موجودة بس في
  `paginateWithStats()` بفلترة/ترقيم صفحات؛ دي بترجع الكل بدون فلتر، زي
  القديمة بالظبط).
- **`UserRepository::monthlySignups()` + `UserRepository::countByRole()`**
  — مضافين، منقولين حرفيًا من القديمة.
- **`ProjectRepository::decisionCounts()`** — مضافة (published+rejected
  count، القديمة نفسها).
- **`AnalyticsService::usersByRoleSeries()` + `AnalyticsService::platformOverview()`**
  — مضافين (الكلاس بقى محتاج `UserRepository` كمان في الكونستركتور — بيتحل
  تلقائي عن طريق الـ container، مفيش binding يدوي محتاج تعديل).
- **`MailService::sendScheduledReport()`** — mail stub جديد، بنفس نمط كل
  ميثودز `MailService` التانية بالظبط (log-فقط لحد ما بنية الإيميل الحقيقي
  تتوصل — نفس قرار بند 1).

## عمدًا مش منقول (موثق في docblock الكلاس نفسه):

أنواع `security_incident_summary` و `vulnerability_summary` محتاجين
`SecurityIncidentRepository`/`VulnerabilityRepository`، وبورتال Security
نفسه لسه ما اتنقلش خالص للارافيل (زي فروع admin/data_analyst/security في
`DashboardsApiController`). هيتضافوا مع بند البورتال، مش هنا.
`ReportsApiController::resolveScope()` عمرها ما تدي دور Security وصول
أصلًا (403 زي أي دور تاني معندوش صفحة Reports).

باقي كل الأنواع (platform-wide + University
المُنطاقة) منقولة بالظبط.

## الروتس المسجّلة (`routes/api.php`، بعد `portfolios` مباشرة):

```
Route::prefix('reports')->middleware('uip.auth')->group(...)
  GET    /api/v1/reports
  POST   /api/v1/reports/generate
  POST   /api/v1/reports/delete-selected
  POST   /api/v1/reports/delete-all
  DELETE /api/v1/reports/{id}
  POST   /api/v1/reports/schedules
  PATCH  /api/v1/reports/schedules/{id}
  POST   /api/v1/reports/schedules/{id}/toggle
  POST   /api/v1/reports/schedules/{id}/run-now
  DELETE /api/v1/reports/schedules/{id}

Route::prefix('admin/reports')->middleware(['uip.auth','uip.admin'])->group(...)
  GET    /api/v1/admin/reports
  POST   /api/v1/admin/reports/generate
  POST   /api/v1/admin/reports/schedules
  POST   /api/v1/admin/reports/schedules/{id}/toggle
  POST   /api/v1/admin/reports/schedules/{id}/run-now
  DELETE /api/v1/admin/reports/schedules/{id}
```

`/{id}` مقيّدة بـ `[0-9]+` عشان `/generate`/`/delete-selected`/`/delete-all`
ميتبلعوش كـ `{id}`.

## التحقق:

- **الفرونت**: كل استدعاء API فعلي في
  `AdminReports.jsx` /
  `UniversityReports.jsx` اتطابق 1:1 مع الروتس فوق — الميثود، المسار،
  وشكل الـ response (`json.data.reports`/`json.data.schedules` للبورتالات
  المُنطاقة، `json.data` + `json.meta.{total,schedules,reportTypes}` للأدمن).
  صفحات `admin/data-analysis`, `security/reports`,
  `university/feed-reports` بتنادي مسارات تانية خالص (`/api/v1/data-analysis/
  reports`، إلخ) — مش جزء من البند ده، بورتالاتها
  نفسها لسه ما اتنقلتش.
- **`php -l`** على الـ 218 ملف بتوع الدلتا كلها — صفر أخطاء (كان فيه خطأ
  حقيقي: تعليق docblock فيه `design_*/security_*` — الـ `*/` جوّه النص ده
  كانت بتقفل الـ comment بدري وتكسر الملف؛ اتصلحت بفواصل عربي "و" بدل `/`).
- **كل dependency في `ReportService`/`ReportSchedulerService`** اتفحصت
  ميثود ميثود مقابل الريبوزيتوريز/سيرفيسز الموجودة فعليًا في اللارافيل —
  اللي كانوا موجودين اتأكد إنهم بنفس الشكل (return type/fields)، واللي
  كانوا ناقصين اتنقلوا حرفيًا من القديمة (مفيش ميثود اتخترعت من الصفر).

## لسه معلّق (مش من البند ده):

- صفحة `/p/{uuid}` (Portfolios) نفسها مش مبنية في React لسه.
- بورتالات Security/Data-Analysis نفسها (بنود 24/25) — عشان كده أنواع
  التقارير بتاعتهم متأجلة هنا.
- `cron` entrypoint فعلي لـ `ReportSchedulerService::runDue()` — الكلاس
  جاهز، بس تسجيله في scheduler حقيقي (كل قد إيه) قرار محتاج صاحب المشروع.
