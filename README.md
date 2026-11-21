# إصلاح: `GET /api/v1/developer/tenants/{id}/usage` (تبويب النشاط)

انسخ الملفات فوق مشروع لارافيل، مفيش migration. مفيش تغيير في الفرونت.
- `usage`: نفس شكل `/developer/usage` (totals, series, keys, apps, top_paths) لكن لمفاتيح وتطبيقات الـ Workspace بس. `?range=1h|24h|7d`.
- `DevUsageService::report()` اتزاد فيه باراميتر `tenantId` اختياري (الاستخدام الشخصي زي ما هو).
- `TenantService` بقى بياخد `DevUsageService` في الـ constructor (بيتحقن تلقائي).
- `routes/developer.php`: لو معدّل عندك، ضيف السطر `Route::get('/usage', [TenantController::class, 'usage'])` جوه مجموعة `/tenants/{id}`.
