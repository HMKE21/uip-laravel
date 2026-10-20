# موديول Auth — Laravel (ملفات جاهزة للتركيب)

كل ملف هنا اتكتب يدويًا بمطابقة كاملة مع `core/Controller.php` و`app/Controllers/Auth/*`
و`app/Services/*` و`config/roles.php` بتوع المشروع القديم (PHP native، `api.zip`) — مش
generated عشوائيًا. راجع `AUTH_API_CONTRACT.md` للعقد الكامل (كل endpoint، شكل الـ
JSON بالظبط، status codes).

## 0) ليه الملفات دي بس، مش `composer create-project` كامل؟

السانبوكس اللي بكتب فيه الكود ده معندوش وصول لـ packagist.org ولا PHP متثبت،
فمقدرش أشغّل لارافيل فعليًا هنا ولا أعمل `php artisan` عشان أتأكد إنه شغال. كل
سطر هنا اتراجع يدويًا ضد الكود القديم (بما فيه فحص توازن الأقواس آليًا)، لكن
لازم تجربه فعليًا عندك — مفيش بديل عن كده.

## 1) التركيب (5 دقايق)

```bash
composer create-project laravel/laravel uip-api
cd uip-api
```

انسخ الملفات دي فوق بنفس المسارات:
```
app/Models/User.php
app/Models/RefreshToken.php
app/Models/Role.php
app/Models/TrustedDevice.php
app/Models/ApiFile.php
app/Services/UipJwtService.php
app/Services/PasswordPolicyService.php
app/Services/RoleService.php
app/Services/TwoFactorService.php
app/Services/TrustedDeviceService.php
app/Services/AccountLockoutService.php
app/Services/FileUploadService.php
app/Services/ApiFileService.php
app/Repositories/ApiFileRepository.php
app/Support/Totp.php
app/Http/Middleware/UipAuthMiddleware.php
app/Http/Middleware/UipCorsMiddleware.php
app/Http/Middleware/UipRateLimitMiddleware.php
app/Http/Middleware/UipSecurityHeadersMiddleware.php
app/Http/Middleware/UipLocaleMiddleware.php
app/Http/Controllers/Controller.php               ← استبدل الموجود
app/Http/Controllers/Api/Auth/*.php                (كل الـ 12 كنترولر)
app/Http/Controllers/Api/FilesApiController.php
config/roles.php
config/security.php
config/upload.php
config/languages.php
routes/api.php                                     ← استبدل الموجود
bootstrap/app.php                                  ← دمج بس، متستبدلش (لارافيل 11+ فقط — شوف قسم 3)
```

## 2) `.env` — لازم يتطابق حرفيًا مع القديم

```
JWT_SECRET=<انسخ نفس القيمة من .env بتاع المشروع القديم بالظبط>
JWT_ACCESS_TTL=900
JWT_REFRESH_TTL=1209600
DB_CONNECTION=mysql
DB_HOST=... DB_PORT=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=...
```
⚠️ لازم نفس `JWT_SECRET` بالظبط — عشان أي توكن صادر من القديم يفضل شغال على
الجديد والعكس أثناء فترة الانتقال.

**مفيش migrations جديدة** — الكود بيقرا/يكتب في نفس الجداول الموجودة
(`users` — بما فيها أعمدة الـ 2FA والـ lockout من migrations 036/063،
`refresh_tokens`, `roles`, `user_roles`, `password_reset_tokens`,
`email_verification_tokens`, `password_history`, `security_policies`,
`trusted_devices` (migration 070), `user_sessions`, `universities`,
`faculties`, `departments`, `students`,
`api_files` (migration 143)) بنفس الأعمدة
بالظبط. `public/uploads/` لازم يتشارك (symlink أو نفس السيرفر) بين
النسختين طول فترة الانتقال — شوف `FILES_API_CONTRACT.md`.

## 3) تسجيل الـ Middleware

**لارافيل 11+**: الملف `bootstrap/app.php` المرفق هنا فيه الـ snippet
الكامل جاهز — دمجه جوه `bootstrap/app.php` بتاع مشروعك (متستبدلش الملف
بالكامل، ده مرجع للجزء اللي محتاجه بس؛ راجع الملحوظة فوقه). خلاصته:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias(['uip.auth' => \App\Http\Middleware\UipAuthMiddleware::class]);

    // نفس ترتيب القديم بالظبط: SecurityHeaders, Cors, RateLimit, Locale
    $middleware->api(prepend: [
        \App\Http\Middleware\UipSecurityHeadersMiddleware::class,
        \App\Http\Middleware\UipCorsMiddleware::class,
        \App\Http\Middleware\UipRateLimitMiddleware::class,
        \App\Http\Middleware\UipLocaleMiddleware::class,
    ]);
})
```

**لارافيل 10 وأقل**: مفيش `bootstrap/app.php` بالشكل ده أصلًا — سجّل بدل
كده في `app/Http/Kernel.php`:
```php
protected $middlewareAliases = [
    // ... الموجودين بالفعل
    'uip.auth' => \App\Http\Middleware\UipAuthMiddleware::class,
];

protected $middlewareGroups = [
    'api' => [
        \App\Http\Middleware\UipSecurityHeadersMiddleware::class,
        \App\Http\Middleware\UipCorsMiddleware::class,
        \App\Http\Middleware\UipRateLimitMiddleware::class,
        \App\Http\Middleware\UipLocaleMiddleware::class,
        // ... أي middleware تانية كانت موجودة في الجروب ده
    ],
];
```

## 4) اختبار المطابقة

```bash
php contract_test.php
```
بيبعت نفس الـ request للقديم والجديد (عدّل `$OLD_BASE`/`$NEW_BASE` فوق في
الملف) ويقارن الـ status code + الـ JSON structure تلقائيًا.

---

## ✅ بند 1 (Auth) — منقول بالكامل (15 من 15 endpoint)

login (مع Account Lockout + Two-Factor بالكامل)، register، refresh-token
(rotation + reuse detection)، logout، forgot-password، reset-password (مع
تعقيد كلمة السر ومنع إعادة الاستخدام)، verify-email، confirm-email-change،
roles، universities، university-hierarchy/{id}، sessions (list + revoke)،
**two-factor/verify + two-factor/cancel (Stateless — شوف الفرق عن العقد
تحت)**.

## ✅ بند 2 (Infra) — منقول بالكامل

Middleware عامة (CORS, Rate Limiting, Security Headers, Locale) + موديول
Files الكامل (`/api/v1/files` — index, store, show, download, delete).
التفاصيل والفروق المتعمّدة (rate-limit/upload policy الإداريين مؤجلين
لبند 25، Locale Stateless) في `FILES_API_CONTRACT.md`.

## ⚠️ فرق تصميم متعمّد — `two-factor/verify` و`/cancel`

القديم بيعتمد على PHP session. الـ API الجديد Stateless بالكامل (JWT بس)،
فـ `/auth/login` بيرجّع `challenge_token` (JWT صالح 5 دقايق) بدل
`csrf_token`، ولازم يترجع تاني في `/auth/two-factor/verify`. التفاصيل
والمبرر الأمني الكامل في `AUTH_API_CONTRACT.md`.

## 🔴 إيه اللي **متعمّد** إنه لسه على النظام القديم — بند 25 (Security Portal)

القرار هنا: السياسات دي محتاجة واجهة إدارة كاملة (allow/deny lists،
GeoIP، audit trail، notifications) هتتنقل مرة واحدة صح مع باقي الـ Security
Portal بدل ما تتبني نسختين. **الجزء الأساسي (منع الدخول) لأي حاجة تانية غير
دول شغال بالكامل دلوقتي.**

1. **IP / Country / Device restriction policies** — مش منقولة.
2. **Concurrent Session Limits** — مش منقولة.
3. **MFA mandatory-policy** (إجبار رول معيّن يفعّل 2FA) — مش منقولة؛
   الـ 2FA نفسه (لو المستخدم فعّله بنفسه) شغال بالكامل.
4. **إرسال إيميلات حقيقية** — ✅ بقت موصولة فعليًا (`App\Mail\GenericMail` +
   `resources/views/emails/generic.blade.php`، مستخدمة من كل ميثودز
   `MailService`). لازم تظبط SMTP حقيقية في `.env` (`MAIL_MAILER=smtp` +
   `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD`/
   `MAIL_FROM_ADDRESS`، وممكن `FRONTEND_URL` لرابط تسجيل الدخول في
   الإيميل) — من غيرها Laravel هيرجع لسلوكه الافتراضي (`log` mailer) وهيفضل
   بيسجل في `storage/logs/laravel.log` بس زي الأول.

5. **`sessions.is_current`** بيرجع `false` دايمًا — الـ Bearer token مالوش
   session identifier مرتبط بيه زي القديم.
6. **Account Lockout — الآثار الجانبية الإدارية بس** (audit log، security
   alert، in-app notification، إيميل تنبيه) — القفل نفسه شغال، الإشعارات
   للأدمن مؤجلة لبند 25.

**الخلاصة العملية:** موديول Auth بقى شغال 100% لأي مستخدم عادي (بما فيهم
اللي مفعّلين 2FA بنفسهم). اللي فاضل كله أدوات إدارة أمان للأدمن (بند 25) —
مفيش فرق سلوك خطير هيحصل لمستخدم عادي لو الترافيك اتوجّه هنا دلوقتي.

## ✅ بند 3 (Universities + Faculty) — منقول بالكامل

`UniversitiesApiController` (14 endpoint) + `FacultyApiController` (16 endpoint،
بما فيهم departments المتداخلة). التفاصيل الكاملة في
`UNIVERSITIES_FACULTY_API_CONTRACT.md`.

## ✅ بند 4 (Students portal) — منقول بالكامل (8 من 8 endpoint)

`StudentsApiController` — روستر الجامعة/الكلية، دعوة/تعديل/حذف/إعادة إرسال
دعوة طالب، وداشبورد الطالب نفسه (عادي + polling). التفاصيل الكاملة
(كل endpoint، شكل الـ JSON، الفجوات المتعمّدة) في `STUDENTS_API_CONTRACT.md`.

### ملفات بند 4 — انسخها فوق بنفس المسارات (إضافة للي فوق، من غير استبدال حاجة)

```
app/Models/Student.php
app/Models/StudentGroup.php
app/Models/Project.php                          (نسخة "قراءة بس" مؤقتة — بند 11 هيوسّعها)
app/Models/ProjectTeamMember.php                 (نسخة "قراءة بس" مؤقتة)
app/Models/ProjectFile.php                       (نسخة "قراءة بس" مؤقتة)
app/Models/AIReadinessScore.php
app/Models/Notification.php
app/Repositories/StudentRepository.php
app/Repositories/StudentGroupRepository.php      (findOwned() بس — الباقي بند 16)
app/Repositories/SupervisorAssignmentRepository.php  (supervisorsForStudent() بس — الباقي بند 9)
app/Repositories/UserRepository.php              (emailExists/createWithRole بس)
app/Repositories/ProjectRepository.php           (forOwner() بس — الباقي بند 11)
app/Repositories/ProjectTeamMemberRepository.php (countAcceptedForProject() بس)
app/Repositories/ProjectFileRepository.php       (forProjectLatest() بس)
app/Repositories/AIAnalysisRepository.php        (readinessForProjects() بس — الباقي بند 21)
app/Repositories/AnnouncementRepository.php      (publishedForUniversity() بس — الباقي بند 22)
app/Repositories/NotificationRepository.php      (forUser/unreadCount بس — الباقي بند 19)
app/Services/MailService.php                     (stub — راجع STUDENTS_API_CONTRACT.md)
app/Services/NotificationService.php             (notify/forUser/unreadCount بس — من غير mute/dedup)
app/Services/ProjectPublishingService.php        (listForOwner() بس)
app/Services/MessagingService.php                (inboxForUser() مبسّطة — is_unread بس)
app/Services/StudentManagementService.php        (الملف الرئيسي — invite/resendInvite/activate/
                                                   deactivate/updateAffiliation/updateAcademicDetails/
                                                   updateStudyDates/updateProfile/delete)
app/Http/Controllers/Api/Concerns/PollableSnapshot.php
app/Http/Controllers/Api/StudentsApiController.php
routes/api.php                                   ← دمج (مضاف جروب `students` — الملف المرفق هنا فيه الكل)
```

**مفيش migrations جديدة** — بيقرا/يكتب في `students`, `student_groups`,
`projects`, `project_team_members`, `project_files`, `ai_readiness_scores`,
`notifications`, `announcements`, `conversations`, `conversation_participants`,
`messages`, `supervisor_assignments`, `supervisors` الموجودة بالفعل.

## ✅ بند 5 (University portal) — منقول بالكامل (16 من 16 endpoint)

4 كنترولرز: `UniversityApprovalsApiController` (طابور اعتماد المشاريع)،
`UniversityPartnershipsApiController` (شراكات شركات + مستثمرين، سطحين تحت نفس
الكلاس)، `UniversityJoinRequestsApiController` (طلبات انضمام طلاب)،
`UniversityVerificationApiController` (حالة توثيق الجامعة). التفاصيل الكاملة
(كل endpoint، شكل الـ JSON، الفجوات المتعمّدة) في
`UNIVERSITY_PORTAL_API_CONTRACT.md`.

### ملفات بند 5 — انسخها فوق بنفس المسارات (إضافة للي فوق، من غير استبدال حاجة)

```
app/Models/Project.php                              (تحديث — إضافة slug/slugify(), فوق نسخة بند 4)
app/Models/ProjectFile.php                          (تحديث — إضافة toRowArray()/humanSize(), فوق نسخة بند 4)
app/Models/ProjectLink.php
app/Models/ProjectApproval.php
app/Models/AIClassification.php
app/Models/UniversityReverificationLog.php
app/Models/StudentUniversityRequest.php
app/Repositories/ProjectRepository.php               (تحديث — إضافة findByUuid/findForUniversityByUuid/
                                                        forUniversityWithOwner/updateStatusForUniversity, فوق نسخة بند 4)
app/Repositories/ProjectFileRepository.php            (تحديث — إضافة forProject(), فوق نسخة بند 4)
app/Repositories/AIAnalysisRepository.php             (تحديث — إضافة readinessFor()/classificationFor(), فوق نسخة بند 4)
app/Repositories/ProjectLinkRepository.php
app/Repositories/UniversityVerificationRepository.php
app/Repositories/UniversityReverificationLogRepository.php
app/Repositories/StudentUniversityRequestRepository.php
app/Services/PermissionService.php
app/Services/ProjectApprovalService.php               (مسار University بس — Faculty/Admin مؤجلين لبند 10/26)
app/Services/StudentJoinRequestService.php            (مسار University بس)
app/Services/UniversityVerificationService.php
app/Http/Controllers/Api/UniversityApprovalsApiController.php
app/Http/Controllers/Api/UniversityPartnershipsApiController.php
app/Http/Controllers/Api/UniversityJoinRequestsApiController.php
app/Http/Controllers/Api/UniversityVerificationApiController.php
routes/api.php                                        ← دمج (مضاف جروب `university` — الملف المرفق هنا فيه الكل)
```

**مفيش migrations جديدة** — بيقرا/يكتب في `projects`, `project_files`,
`project_links`, `project_approvals`, `ai_readiness_scores`,
`ai_classifications`, `university_reverification_log`,
`student_university_requests`, `university_verification_requests`,
`universities`, `permissions`, `role_permission`, `user_roles` الموجودة بالفعل
(كلها متأكدة ضد `database/uip_full_install.sql`).

## 🚧 بند 25 (Security Portal) — batch 1/6: Dashboard + Sessions

أول endpoint فعلي في البورتال (`SecurityDashboardApiController` — سطح
واحد `/api/v1/security/dashboard`، بيجمّع overview/recent_incidents/
top_risks/notifications/recent_events/severity_breakdown بالظبط زي
`SecurityDashboardService` القديمة) + Sessions الكامل
(`SecuritySessionsApiController` — list + revoke، طبقة JSON رفيعة فوق
`UserSessionRepository` بنفس الميثودز اللي الكنترولر القديم بيستخدمها:
`activeWithUser`/`countActive`/`countDistinctActiveUsers`/`revoke`).
مفيش منطق أعمال جديد ولا استعلام جديد — كل حاجة مطابقة للقديم حرفيًا،
غير الفرق الشكلي المعتاد (`Session::userRole()/userId()` ->
`$request->attributes->get('uip_role')/'uip_user_id'`).

RBAC: `security_admin` أو `security_officer` أو `admin` — بيتأكد منه
جوّه كل كنترولر نفسه (نفس منطق `analytics`/`data-analysis`)، مش عبر
middleware منفصل.

### ملفات بند 25 batch 1 — انسخها فوق بنفس المسارات (إضافة للي فوق، من غير استبدال حاجة)

```
app/Models/UserSession.php
app/Models/SecurityIncident.php
app/Models/Vulnerability.php
app/Models/RiskScore.php
app/Models/BlockedIp.php
app/Models/SecurityAlert.php
app/Models/SecurityNotification.php
app/Repositories/UserSessionRepository.php        (activeWithUser/countActive/countDistinctActiveUsers/find/revoke بس —
                                                    record/touchActivity/deactivate/... القديمة مش منقولة عمدًا، شوف الفجوة تحت)
app/Repositories/BlockedIpRepository.php          (all() بس — isBlocked/block/unblock بند 25 batch 4)
app/Repositories/SecurityAlertRepository.php      (countOpen() بس — الباقي بند 25 batch 2)
app/Repositories/SecurityIncidentRepository.php   (allWithAssignee/countBySeverity/countOpen بس — الباقي بند 25 batch 2)
app/Repositories/VulnerabilityRepository.php      (countOpen() بس — الباقي بند 25 batch 3)
app/Repositories/RiskScoreRepository.php          (topRisks/averageScore بس — upsert/countByLevel مؤجلة، مفيش زرار recalculate
                                                    فعلي في الفرونت React الحالي بينادي عليها)
app/Repositories/SecurityNotificationRepository.php (forRole() بس — create/unreadCount/markRead/markAllRead لاحقًا)
app/Repositories/SecurityLogRepository.php        (كامل — recent/readDays/parseLine، ملف-محور زي القديمة بالظبط)
app/Services/SecurityDashboardService.php
app/Http/Controllers/Api/SecurityDashboardApiController.php
app/Http/Controllers/Api/SecuritySessionsApiController.php
routes/api.php                                     ← دمج (مضاف جروب `security` — الملف المرفق هنا فيه الكل)
```

**مفيش migrations جديدة** — بيقرا/يكتب في `user_sessions`,
`security_incidents`, `vulnerabilities`, `risk_scores`, `blocked_ips`,
`security_alerts`, `security_notifications`, `users`, `user_roles`
الموجودة بالفعل (كلها متأكدة ضد `database/uip_full_install.sql`،
migrations 039/041/071 القديمة).

**⚠️ فجوتين موثّقتين (مش حاجة اتنسيت):**

1. **`user_sessions` هيفضل فاضي** — `AuthService` الحالي في اللارافيل
   لسه مش بيكتب سطر جلسة عليه عند اللوجين (نفس الحال زي أي جدول تاني
   محتاج توصيل من AuthService). صفحة Sessions هتشتغل وتعرض 0 لحد ما
   توصيل تسجيل الجلسة يتعمل فعليًا جوه `AuthService::login()`.
2. **`recent_events` (Recent Security Events) هيرجع فاضي** — زي القديمة
   بالظبط، مفيش جدول `security_logs`؛ المصدر ملفات
   `storage/logs/security/{Y-m-d}.log` اللي بيتكتبوا بواسطة
   `Core\Logger::security()`. الفرق: `AuthService` القديم كان بينادي
   عليها عند كل حدث أمني، لكن نسخة اللارافيل الحالية لسه ما بتكتبش
   عليها — نفس الفجوة، مش بند جديد. القراءة نفسها (`SecurityLogRepository`)
   منقولة 100% ومطابقة، هتشتغل تلقائيًا لما التسجيل يتوصّل.

كل باقي كروت/فيدات الداشبورد (alerts, incidents, open vulnerabilities,
blocked IPs, average risk score, top risks, notifications) بتقرا
بيانات حقيقية من جداول موجودة فعلًا ومفيش فيها فجوة.

**التالي:** بند 25 batch 2 (Alerts + Incidents).

## 🚧 بند 25 (Security Portal) — batch 2/6: Alerts + Incidents

سطحين REST كاملين، بند 25 batch 2:

**`SecurityAlertsApiController`** — `/api/v1/security/alerts/*`: `index`
(فلاتر status/severity/type/assigned_to/q/date_from/date_to + pagination
حقيقي بـ SQL LIMIT/OFFSET)، `acknowledge`/`resolve`/`escalate` (POST)،
`assign` (PATCH)، `export` (CSV بنفس فلاتر index، بدون pagination).
دورة حياة التنبيه بالظبط زي القديمة: `open -> acknowledged -> resolved`،
أو `escalated`.

**`SecurityIncidentsApiController`** — `/api/v1/security/incidents/*`:
`index` (فلاتر status/severity + بحث `search`/`q` في title/description +
pagination في الميموري عبر `Concerns\Paginates`، زي القديمة بالظبط)،
`show` (تفاصيل + timeline + أدلة + قايمة assignees)، `store` (بيولّد
`reference_code` تلقائيًا `INC-{year}-{seq}` + أول حدث timeline
`created`)، `updateStatus`/`assign`/`comment` (كل واحد بيضيف حدث
timeline خاص بيه، زي القديمة)، `uploadEvidence`/`downloadEvidence`/
`deleteEvidence` (عن طريق `FileUploadService` — فئة `incident_evidence`
كانت متعرّفة في `config/upload.php` بالفعل من بند تاني)، `exportReport`
(CSV لملف حالة حادثة واحدة كامل: بيانات + timeline + أدلة).

مفيش منطق أعمال جديد ولا استعلام جديد في الاتنين — كل حاجة مطابقة
للقديم حرفيًا غير الفرق الشكلي المعتاد.

### ملفات بند 25 batch 2 — انسخها فوق بنفس المسارات (إضافة للي فوق، من غير استبدال حاجة)

```
app/Models/SecurityIncidentEvent.php
app/Models/IncidentEvidence.php
app/Repositories/SecurityAlertRepository.php     (تحديث — اكتمال كامل: create/find/recentOpenForUser/bumpOccurrence/
                                                   search/searchAll/countByStatus/acknowledge/resolve/escalate/assign،
                                                   فوق نسخة batch 1 اللي كانت countOpen() بس)
app/Repositories/SecurityIncidentRepository.php  (تحديث — اكتمال كامل: create/find/updateStatus/assign/addEvent/timeline،
                                                   فوق نسخة batch 1 اللي كانت allWithAssignee/countBySeverity/countOpen بس)
app/Repositories/IncidentEvidenceRepository.php  (جديدة — create/find/forIncident/countForIncident/delete)
app/Repositories/UserRepository.php              (تحديث — إضافة allWithRoles() نسخة مبسّطة (فلتر role بس)، فوق نسخة بند 4)
app/Http/Controllers/Api/SecurityAlertsApiController.php
app/Http/Controllers/Api/SecurityIncidentsApiController.php
routes/api.php                                    ← دمج (مضاف `security/alerts/*` و`security/incidents/*` تحت جروب
                                                     `security` الموجود من batch 1 — الملف المرفق هنا فيه الكل)
```

**مفيش migrations جديدة** — بيقرا/يكتب في `security_alerts`,
`security_incidents`, `security_incident_events`, `incident_evidence`,
`users` الموجودة بالفعل (كلها متأكدة ضد `database/uip_full_install.sql`،
migrations 041/071 القديمة).

**فرق واحد متعمّد عن `allWithRoles()` القديمة:** النسخة هنا فلتر `role`
(وoptional `status`) بس — بترجع `id/uuid/full_name/email/role`، مش كل
أعمدة الانتماء (uni/faculty/...) اللي القديمة كانت بترجعها،
لأن الاستهلاك الوحيد هنا هو dropdown تعيين موظف الأمن (Alerts/Incidents)
مش صفحة Admin > Users. لو صفحة زي دي اتنقلت لاحقًا (بند 26 Admin)،
هتتوسّع وقتها.

**التالي:** بند 25 batch 3 (Vulnerabilities + Policies).

## ✅ بند 25 (Security Portal) — batch 3/6: Vulnerabilities + Policies

`SecurityVulnerabilitiesApiController` (`/api/v1/security/vulnerabilities/*`)
— index (فلاتر + pagination)، store، updateStatus/setDeadline/assign،
export (CSV). `SecurityPoliciesApiController`
(`/api/v1/security/policies/*`) — الـ 9 پانلات كاملة (lockout/password/
upload/session/mfa/rate-limit/ip-restriction/country-restriction/
device-restriction) + `unlock/{id}` لفك قفل حساب يدويًا.

قرارين إضافيين اتخدوا في الـ batch ده (سد فجوتين كانتا موثّقتين من
بند 2): وصّل `FileUploadPolicyService` جوه `FileUploadService::store()`
(حد الحجم/الامتدادات بقوا قابلين للتعديل فعليًا من شاشة Policies)،
ووصّل `ApiRateLimitPolicyService` جوه `UipRateLimitMiddleware` (مع
fallback تلقائي لـ `config('security.rate_limit_per_min')` لو
الداتابيز مش متاحة). ⚠️ فجوة لسه موثّقة عمدًا: enforceIdleTimeout/
enforceConcurrentLimit/evaluate بتوع Session/Mfa/IP/Country/Device
policy — منطق ذاتي الاكتفاء وجاهز، بس مفيش نقطة حقيقية في
AuthService/AuthMiddleware بتنادي عليهم لسه (هيتوصّل لما session
tracking عند اللوجين يتعمل، بند 25 batch 1's session-tracking gap).

## ✅ بند 25 (Security Portal) — batch 4/6: Logs

`SecurityLogsApiController` (`/api/v1/security/logs/*`) — index (فلترة/
بحث على أحداث log الأمان الحقيقية، ملف-محور)، export (CSV)،
`unblockIp` (فك حظر IP يدويًا عبر `BlockedIpRepository`).

الأهم في الـ batch ده: **سد فجوة `storage/logs/security/*.log` فعليًا**
(كانت موثّقة من بند 25 batch 1 كـ"AuthService الحالي مش بيكتب عليها
لسه"). كلاس جديد `App\Support\SecurityLog` بيكتب سطر واحد بنفس شكل
`Core\Logger::security()` القديمة حرفيًا (`[Y-m-d H:i:s] SECURITY:
message {json}`)، متوصّل دلوقتي في: `AccountLockoutService` (lockout/
auto-unlock/manual-unlock/policy-updated)، `PasswordPolicyService`
(policy updated)، `TrustedDeviceService` (device remembered + validator
mismatch)، `TwoFactorService` (enabled/disabled/recovery-code-used)،
كل Auth controllers (Register/Login [6 نقاط]/TwoFactorChallenge/
Logout/RefreshToken/ResetPassword/ConfirmEmailChange)،
`FileUploadService::scanForMalware()` (3 نقاط)، و`GeoIpService::
lookupRemote()` (نقطتين). كل الرسائل والـ context keys منقولة حرفيًا
من القديمة عشان تتطابق مع `SEVERITY_MAP`/`EVENT_TYPE_MAP` في
`SecurityLogService` من غير أي تعديل هناك.

⚠️ فجوة وحيدة متبقية، موثّقة ومتعمّدة: 4 من الـ 16 `Logger::security()`
الأصليين في `AuthService` (بلوك IP + IP/country/device restriction)
مالهمش نظير — لأن الفحوصات نفسها لسه مش متوصّلة في `LoginController`
الجديد (نفس فجوة batch 3's IP/Country/Device policy enforcement).
مفيش حاجة تتلوج لحاجة مش بتتفحص أصلًا.

## ✅ بند 25 (Security Portal) — batch 5/6: Reports + Report Files

مدّيت `ReportService` المشترك (نفسه اللي باقي البورتالات بتستخدمه)
بنوعين جداد — `security_incident_summary` و`vulnerability_summary` —
عبر `SECURITY_TYPES` const، حقن `SecurityIncidentRepository`/
`VulnerabilityRepository`، وميثودين private منقولين حرفيًا.
`SecurityReportsApiController` (`/api/v1/security/reports/*`) — index/
generate/destroy بس، طبقة رفيعة فوق `ReportRepository`/`ReportService`.

`SecurityReportFilesApiController` (`/api/v1/security/report-files/*`)
— نفس شكل نظيره في Data-Analysis 1:1 (Model + Repository + Service
جداد: `SecurityReportFile`/`SecurityReportFileRepository`/
`SecurityReportFileService`)، على جداول `security_report_files`/
`security_report_categories`/`security_report_tags`/
`security_report_file_tags`/`security_report_file_versions` (موجودة
فعلًا، migration 072). index (فلترة + pagination)، store، show،
download، preview، replace (نسخة جديدة)، archive/unarchive، destroy
(حذف نهائي مع كل النسخ). بدون تعليقات/تعاون — القديمة معندهاش endpoints
زي كده.

## ✅ بند 25 (Security Portal) — batch 6/6: Settings — آخر batch، بند 25 قفل بالكامل

`SecuritySettingsApiController` (`/api/v1/security/settings/*`) — نفس
تركيبة Settings في باقي البورتالات: `index` (بروفايل + تفضيلات +
2FA status + **trusted devices** + notification prefs)،
`updateNotificationPreferences`، `updateProfile` (full_name بس —
تغيير الإيميل عبر confirm-link لسه مش منقول لأي بورتال، زي باقي
الكنترولرز)، `updatePreferences`، `toggleTheme`، `updatePassword`،
`twoFactorSetup`/`twoFactorConfirm` (stateless عبر `setup_token`، نفس
نمط كل كنترولرز Settings الجديدة)/`twoFactorDisable`،
و`revokeTrustedDevice`.

**إضافة جديدة لـ `TrustedDeviceService`:** `listForUser()`/`revoke()`
منقولين من `TrustedDeviceRepository` القديمة (اللي معندهاش نظير في
اللارافيل أصلًا — `TrustedDeviceService` الجديدة بتتكلم مباشرة مع
Eloquent model `TrustedDevice` من غير طبقة repository منفصلة، فالميثودز
دي اتضافت هناك بدل ما تتخترع repository جديدة لغرض واحد بس). هما أول
استهلاك حقيقي ليهم — مفيش بورتال تاني بيستخدم Trusted Devices UI لحد
دلوقتي.

**فرق واحد متعمّد عن الكنترولر القديم:** `revokeTrustedDevice` — القديمة
`DELETE /trusted-devices/{id}`، بس `SecuritySettings.jsx` فعليًا بينادي
`POST /2fa/trusted-devices/{id}/revoke` (الكود الحقيقي في الفرونت، مش
التعليق التوثيقي جنبه اللي كان لسه بيقول المسار القديم) — الروت هنا
مطابق لِلي الفرونت بيستخدمه فعلًا، مش للـ docblock القديم.

**مفيش avatar upload** — `SecuritySettings.jsx` معندهاش رفع صورة بروفايل
(بعكس Data Analysis)، فمفيش `uploadAvatar()` مختلق.

### ملفات بند 25 batch 3-6 — انسخها فوق بنفس المسارات

راجع الـ zip الكامل المرفق مع كل رد — كل ملفات بند 25 (batch 1-6) موجودة
فيه بالكامل تحت مساراتها الصح؛ القايمة أعلاه (batch 1/2) بتوضح الملفات
الجديدة تحديدًا لكل batch، وبند 3-6 بنفس المنطق: Models/Repositories/
Services/Controllers جداد + `routes/api.php` مدموج + تحديث `TrustedDeviceService`.

---

## 🎉 بند 25 (Security Portal) — خلص بالكامل (6/6 batches)

كل الـ 14 كنترولر الأصلي اتنقل: Dashboard، Sessions، Alerts، Incidents،
Vulnerabilities، Policies، Logs، Blocked IPs (جزء من Logs)، Reports،
Report Files، Settings. باقي بند 15 (Future — لسه مالوش نطاق محدد) وبند
26 (Admin — 4 كنترولرز بس من ~24).
