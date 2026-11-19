# UIPE Mail — المرحلة 4 (Backend): Multi-Tenant للجامعات

## الجديد
- `mail_tenants` (tenant لكل جامعة: حصص + سياسات + إيقاف)، `mail_domains` (نطاقات: pending → active بتفعيل أدمن المنصة)، وأعمدة جديدة في `mail_accounts`
  (`tenant_id, domain_id, member_type, member_id, quota_mb, suspended_reason`).
- الـ migration بتعمل tenant + نطاق primary مفعّل تلقائيًا للجامعات اللي عندها `universities.email_domain`.
- `MailTenantService`: النطاقات، السياسات، الحصص، Provisioning (student | staff | faculty | university)، إدارة الصناديق.
- اتربط بـ `MailboxService`: منع الإرسال لو الصندوق/الجامعة موقوفة، حد المستلمين وحد المرفق من سياسة الجامعة،
  "الطالب يبعت لجامعته بس"، منع الخارجي (`allow_external=false`)، رفض الإرسال/الرفع/الاستيراد لصندوق ممتلئ، وتخطي الجامعة الموقوفة في التسليم.
- `GET /api/v1/mail/account` بيرجّع `quota` (للصندوق المؤسسي) وحقول `tenant_id/member_type/tenant_status` في كل حساب.

## API — `/api/v1/mail-admin` (الأدوار: university = كل شيء، faculty = عرض صناديق كليته، admin = كل الجامعات بـ `university_id`)
| Method | Path | الوصف |
|---|---|---|
| GET | /tenant | الـ tenant + السياسات + النطاقات + الإحصائيات |
| PUT | /tenant/policies | `quota_student_mb, quota_staff_mb, quota_admin_mb, max_attachment_mb, max_recipients, student_send_scope(platform\|university), allow_external` |
| GET/POST | /domains | عرض / إضافة نطاق (يبدأ pending) |
| POST | /domains/{id}/primary | نطاق مفعّل ← primary (بيزامن `universities.email_domain`) |
| DELETE | /domains/{id} | حذف نطاق (مش primary ومفيهوش صناديق) |
| GET | /members?type=&status=&q=&page=&per_page= | صناديق الجامعة مع الحصة والمستخدم |
| PATCH | /accounts/{id} | `status(+suspended_reason), quota_mb(null=افتراضي), display_name, local` |
| POST | /provision | `{types:[student,staff,faculty,university], faculty_id?}` — آمن للتكرار (حد 2000/طلب، `remaining` بيقولك لو فاضل) |
| GET | /tenants | (أدمن المنصة) كل الجامعات |
| POST | /tenants/{universityId}/suspend \| resume | (أدمن المنصة) إيقاف/تشغيل الجامعة كلها |
| POST | /platform-domains/{id}/activate \| disable | (أدمن المنصة) تفعيل/تعطيل نطاق |

## التشغيل
`php artisan migrate` ثم `php artisan test --filter=MailTenantTest` (8 اختبارات).
