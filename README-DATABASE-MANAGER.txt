UIPE Database Manager - الباك اند (Laravel) - الدفعة الأولى
=========================================================
فك الضغط فوق مجلد uip-laravel-main (نفس المسارات).

جديد:
- app/Services/Tenancy/TenantDataGuard.php      حارس SQL والأعمدة/الجداول الحساسة
- app/Services/Tenancy/TenantDataService.php    Grid + تعديل صف + SQL للقراءة + هيكل وعلاقات + تدقيق
- app/Http/Controllers/Api/TenantDataController.php
- tests/Unit/TenantDataGuardTest.php

معدّل:
- config/tenancy.php          (قسم 'data' في الآخر)
- app/Services/Tenancy/TenantService.php   (ability 'data' => owner فقط)
- app/Services/Tenancy/TenantRoles.php     (تسميات data.* في سجل النشاط)
- routes/developer.php        (/tenants/{id}/data/*)

Endpoints: GET /data, POST /data/schema, /data/rows, /data/row, /data/insert, PUT /data/update, POST /data/delete, /data/sql

الحالة:
- الباك اند مكتوب بالكامل. الفرونت (تاب قاعدة البيانات في DevTenants.jsx + tenantApi.js) لسه ما اتكتبش.
- ما اتعملش php lint ولا phpunit (مفيش PHP في البيئة دي). منطق الحارس اتجرب بنسخة Python مطابقة.
  شغّل: php artisan test --filter=TenantDataGuardTest
- للإنتاج: اضبط TENANCY_DB_USERNAME / TENANT_DATA_DB_USERNAME على مستخدم MySQL صلاحياته على قواعد العملاء فقط.
