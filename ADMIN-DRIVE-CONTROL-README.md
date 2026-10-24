# Admin → My PC Control (backend)

بعد ما تنسخ الملفات: `php artisan migrate` (جدول `drive_user_limits` جديد). مفيش تغيير في الجداول القديمة.

كل الـ endpoints تحت `/api/v1/admin/drive/control/*` (uip.auth + uip.admin). الشرح الكامل في docblock
`app/Http/Controllers/Api/AdminDriveControlApiController.php`.

## الفكرة
- **الإعدادات** (`DriveAdminSettings`): كتالوج بيتخزن في `settings` بمفتاح `drive_<key>`؛ لو مفيش override بيرجع لـ `.env`/`config/drive.php`.
  بتتطبّق تلقائيًا على `config('drive.*')` من constructor الـ DriveService، فكود "جهازي" القديم بياخدها من غير تعديل.
- **الحصة**: تخصيص المستخدم > حصة الدور (`role_quota_mb`) > الحصة العامة. `0` = بلا حد.
- **حالة المستخدم**: `active` · `readonly` (عرض وتنزيل بس — أي POST/PUT/PATCH/DELETE بيتمنع) · `blocked` (مقفول). + منع رفع / منع مشاركة لمستخدم بعينه.
- **مفاتيح عامة**: `feature_drive` (إيقاف "جهازي" كله + رسالة صيانة)، `feature_uploads`، `feature_sharing`، `feature_archive`، `feature_terminal`.
- **الرفع**: `blocked_extensions` (بيتفحص في الرفع، الرفع المباشر، إنشاء ملف، إعادة التسمية).
- **المشاركة**: أقصى عدد روابط، أقصى مدة، منع "بدون انتهاء"؛ ولما "جهازي"/المشاركة تتقفل أو صاحب الرابط يتقفل، الروابط العامة بترجع 403.
- **الأدمن نفسه**: `admin_can_download` / `admin_can_delete` يقفلوا التنزيل/الحذف من لوحة الأدمن. كل فتح/تنزيل/حذف/تغيير حد بيتسجّل في `audit_logs` بـ `admin.drive.*`.

## الملفات
جديد: `DriveAdminSettings`, `DriveAdminService`, `AdminDriveControlApiController`, `DriveUserLimit` + migration.
معدّل: `DriveService` (حصة/حد ملف لكل مستخدم، مفاتيح، امتدادات)، `DriveShareService`، `DriveApiController`، `DriveShareApiController`، `DesktopSettingsApiController`، `routes/api.php`.
