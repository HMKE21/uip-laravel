# البند 3 — الأدوار والصلاحيات (باك اند)

بعد فك الـ zip فوق المشروع: `php artisan migrate`

## الجديد
- **migration** `2027_12_30_100000_create_tenant_roles_teams.php`: `tenant_custom_roles` (مصفوفة صلاحيات JSON)، عمود `tenant_members.custom_role_id`، `tenant_teams`، `tenant_team_members`.
- **TenantPermissions**: أقسام (members, teams, branding, site, features, domains, embed, resources, activity) × مستوى none | view | edit.
- **TenantRoles** (خدمة): أدوار مخصصة، أقسام/فرق، سجل النشاط (changes = مين غيّر إيه، logins = مين دخل).
- **TenantService**: `authorize` و `authorizeRead` بيحترموا الدور المخصص؛ منع إعطاء صلاحيات أكبر من صلاحياتك؛ `gate` للـ API؛ القيمة `custom:<id>` مقبولة كـ role في إضافة/تغيير عضو؛ `members` بترجّع `custom_role` و `teams`.
- **TenantSiteService**: القراءة بتحترم قسم site.
- **DevLogService**: بيختم tenant_id وIP على أحداث الإدارة، والاحتفاظ بسجل النشاط 365 يوم (`DEV_AUDIT_LOG_DAYS`).

## Endpoints (تحت /api/v1/developer/tenants/{id})
- GET/POST `/roles` · PUT/DELETE `/roles/{roleId}`
- GET/POST `/teams` · PUT/DELETE `/teams/{teamId}`
- GET `/activity?type=changes|logins&action=&user_id=&q=&from=&to=&page=&per_page=`
- POST/PUT `/members` بيقبل role = `custom:<id>`

## ملاحظات
- المالك والأدمن بس يديروا الأدوار والأقسام ويشوفوا السجل. الدور المخصص ما يقدرش يدير الأدوار أو يحذف المساحة.
- حذف دور مخصص بيرجّع أعضاؤه لـ viewer.
- لو الـ migration لسه متعملش، الباك اند بيشتغل زي الأول والـ endpoints الجديدة بترجع 503.
- مفيش اختبارات اتشغلت (PHP syntax check بس).
