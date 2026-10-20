# جهازي (My PC) — تخزين سحابي مباشر + نسخ احتياطي على Google Drive / OneDrive

## 1) الرفع/التنزيل المباشر (Cloudflare R2 أو Amazon S3)
المتصفح بيرفع وينزّل **مباشرة** من الـ bucket بروابط موقّعة قصيرة العمر، والسيرفر بيحفظ الـ metadata بس (الاسم، المسار، الحجم).
لو الإعداد ناقص أو فشل الرابط، الواجهة بترجع تلقائيًا للمسار القديم (الملف بيعدّي على Laravel) — فمفيش حاجة بتتعطل.

1. `composer update league/flysystem-aws-s3-v3` (اتضافت في composer.json).
2. اعمل bucket **Private** في Cloudflare R2 (أو S3) وAPI token بصلاحية Object Read & Write على الـ bucket ده.
3. متغيرات البيئة على Railway:
```
DRIVE_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_BUCKET=...
AWS_DEFAULT_REGION=auto            # R2: auto
AWS_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com   # R2 فقط
AWS_USE_PATH_STYLE_ENDPOINT=true   # R2 / MinIO
DRIVE_DIRECT=true                  # افتراضي
DRIVE_DIRECT_TTL_MIN=15
```
4. **CORS على الـ bucket** (ضروري عشان المتصفح يرفع مباشرة) — مثال R2:
```json
[{
  "AllowedOrigins": ["https://uip-v1.vercel.app"],
  "AllowedMethods": ["GET", "PUT", "HEAD"],
  "AllowedHeaders": ["*"],
  "ExposeHeaders": ["ETag"],
  "MaxAgeSeconds": 3600
}]
```
5. ملاحظة: الملفات الموجودة حاليًا على الـ disk القديم لازم تتنقل للـ bucket (rclone / aws s3 sync) بنفس المسارات `u{id}/{uuid}` قبل التحويل.

الـ endpoints الجديدة (كلها تحت `/api/v1/drive`): `GET /config`, `POST /upload/init` + `/upload/complete`,
`POST /nodes/{id}/replace/init` + `/replace/complete`, `GET /nodes/{id}/download-url`, `GET /manifest`.

## 2) النسخ الاحتياطي على Google Drive / OneDrive (من المتصفح)
"جهازي" على سيرفر الجامعة يفضل **النسخة النهائية**. النسخة الاحتياطية بتتعمل من متصفح الطالب لدرايفه هو، والتوكنات مبتوصلش للسيرفر.
- **Google:** `GOOGLE_CLIENT_IDS` (الموجود أصلًا). فعّل Google Drive API في نفس المشروع، وأضف `https://uip-v1.vercel.app` في Authorized JavaScript origins. النطاق المستخدم `drive.file` فقط.
- **Microsoft:** سجّل تطبيق SPA في Entra ID، Redirect URI = `https://<front-domain>/ms-auth.html`، الصلاحيات `Files.ReadWrite.AppFolder` + `User.Read`، ثم:
```
MS_CLIENT_ID=...
MS_AUTHORITY=https://login.microsoftonline.com/common
```
حسابات الجامعة (Entra) ممكن تحتاج موافقة الـ admin.
