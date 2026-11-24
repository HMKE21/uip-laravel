# UIPE Realtime Apps — Laravel (الخطوة 2 من 3)

## الملفات (انسخها فوق المشروع بنفس المسارات)
- app/Http/Controllers/Api/AdminSfuAppsApiController.php   (جديد)
- app/Services/SelfHostedSfuService.php                     (معدّل: ميثود adminRequest بس)
- routes/api.php                                            (معدّل: 8 سطور جوه prefix control — دور على "sfu-apps")
- config/webrtc.php                                         (معدّل: مفتاح platform.public_url)
> لو عندك تعديلات تانية على routes/api.php أو config/webrtc.php انقل التعديلات يدويًا بدل ما تستبدل الملف.

## .env (جديد)
UIPE_REALTIME_PUBLIC_URL=https://sfu.yourdomain.com   # العنوان العلني اللي هيتعطى للمطوّرين (https + دومين). اختياري دلوقتي.
ثم: php artisan config:clear && php artisan route:clear
مفيش migrations (التطبيقات متخزنة على سيرفر الـ SFU في data/apps.json).

## لازم قبل التجربة
حدّث سيرفر الـ SFU بالـ zip الأول (uipe-sfu-apps-progress.zip) وأعد تشغيله، وإلا هيرجع 404 "old version".

## الـ endpoints (admin فقط) تحت /api/v1/admin/meetings/control/sfu-apps
GET / · POST / · GET {id} · PATCH {id} · POST {id}/rotate-secret · POST {id}/close-rooms · DELETE {id}

## تجربة سريعة (curl بتوكن الأدمن)
curl -X POST https://uip-nub.vercel.app/api/v1/admin/meetings/control/sfu-apps \
  -H "Authorization: Bearer <ADMIN_TOKEN>" -H "Content-Type: application/json" \
  -d '{"name":"Test","owner_name":"Ali","owner_email":"ali@example.com"}'
→ بيرجّع app.id + secret (مرة واحدة). بعدها:
curl https://<SFU_PUBLIC>/v1/apps/<APP_ID>/ping -H "Authorization: Bearer <SECRET>"
(غيّر الـ host حسب مكان الـ API عندك؛ الـ auth ممكن يكون cookie مش Bearer حسب إعدادكم.)
