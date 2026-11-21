# Multi-tenancy — الخطوة 5: عزل الملفات والـ realtime والـ queue (باك اند)

> الحالة: اتفحصت syntax بـ `php -l` بس. **ما اتشغلتش اختبارات** (مفيش vendor عندي). جرّبها على staging.

## اللي اتعمل
| المجال | الملفات | الفكرة |
|---|---|---|
| الملفات | `Services/Tenancy/TenantStorage.php` (جديد) + `TenantContext.php` | مسارات الرفع `uploads/avatars` ⟵ `uploads/avatars/t5`، و disks (local/public/drive/s3) بـ root `tenants/5`. UploadMirror (S3) مفتاحه فيه t5 تلقائيًا. |
| روابط موقّعة | `ResolveTenant.php` + 5 مواضع `temporarySignedRoute` | الرابط بيشيل `tn=<id>` (متوقّع) لأن `<img>/<video>` مبيبعتوش `X-Tenant-Domain`. |
| مسارات ثابتة | `ReportService`, `ProfilePhotoService`, `ChunkedUploadService`, `ExamExportsApiController`, `SecurityLog`, `SecurityLogRepository` | بقت معزولة (t5). |
| Realtime | `RealtimePing`, `PostsPing`, `RealtimeAuthController`, `RealtimeConfigController`, `UipCorsMiddleware` | القنوات: `private-t5.App.Models.User.{id}` و `private-t5.posts.feed`. `GET /realtime/config` بيرجّع `channel_prefix` (والـ Vary بقى فيه X-Tenant-Domain). المنصة الأم: بادئة فاضية = زي ما هي. |
| Queue | `Services/Tenancy/TenantQueue.php` (جديد) + `AppServiceProvider` | الجوبز في المنصة الأم + `tenant_id` في الـ payload، والـ worker يفعّل العميل حوالين الجوب. worker واحد كفاية. |
| Scheduler | `Console/Commands/TenantsRun.php` (جديد) + `routes/console.php` | `tenants:run "<أمر>" --feature=x` بيلف على العملاء الجاهزين (ويتخطى اللي الميزة مقفولة). |

## ملاحظات
- اجتماعات (`presence-meeting.{uuid}`) مش مبادَّرة: الـ uuid فريد والـ auth بيدوّر في قاعدة العميل.
- الفرونت (خطوة 6) لازم: يبعت `X-Tenant-Domain`، ويستخدم `channel_prefix` في أسماء قنوات Echo.
- الإيميلات (SMTP/From لكل عميل) لسه مش معزولة.
- لازم `queue:work` و `schedule:run` يفضلوا شغالين زي الأول؛ مفيش تغيير في طريقة التشغيل.
