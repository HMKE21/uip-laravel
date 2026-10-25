# التحديث اللحظي (WhatsApp-speed) — Reverb

## الفكرة
- سوكت واحد (Reverb) مفتوح لكل مستخدم على قناته الخاصة `App.Models.User.{id}`.
- أي كتابة ناجحة في **الرسايل / الإشعارات / My PC** بتبعت "ping" صغير بعد ما الرد يرجع للي كتب (مفيش تأخير عليه).
- الفرونت أول ما ييجيله الـ ping بيجيب الجديد فورًا (poll للمحادثة، الجرس، قايمة الملفات). الـ polling القديم فضل كـ safety net بس.
- الـ ping بيشمل: رسالة جديدة، تعديل، حذف، ريأكشن، قراءة (✓✓)، تصويت، "بيكتب…"، إشعار جديد، وأي تغيير في ملفات جهازي.

## الباك (uip-laravel) — ملفات جديدة
- `app/Events/Realtime/RealtimePing.php`
- `app/Support/Realtime.php`
- `app/Http/Middleware/UipRealtimeMiddleware.php`
- `app/Http/Controllers/Api/RealtimeAuthController.php`
## الباك — ملفات معدّلة
- `routes/api.php` (route `realtime/auth` + `uip.realtime` على groups: drive / messaging / notifications)
- `bootstrap/app.php` (alias `uip.realtime`)
- `app/Services/NotificationService.php` (ping مع كل إشعار جديد)

## الفرونت (uip-raect) — جديد
- `src/lib/realtime.js`, `src/components/RealtimeBridge.jsx`
## الفرونت — معدّل
- `src/App.jsx` (تركيب RealtimeBridge)
- `src/hooks/useLive.js` (خيار `topics`)
- `src/pages/Messages.jsx` (الـ poll بيتشغّل لحظيًا + الإنبوكس)
- `src/components/Topbar.jsx`, `src/layouts/DashboardLayout.jsx`, `src/desktop/notifications.js`, `src/pages/Notifications.jsx` (topics)
- `src/components/FileViewer.jsx` (الملف المفتوح بيتحدّث لما يتحفظ من جهاز تاني)
- My PC نفسه (`StudentMyPC` + سطح المكتب) كان بيسمع `uip-drive-changed` أصلًا، فبقى يتحدّث لحظيًا من غير تعديل.

## الإعداد (ده أهم جزء — من غيره مفيش لحظي)
المشكلة الحالية: `BROADCAST_CONNECTION=log` وبيتم الاتصال بـ `wss://...up.railway.app:8080` وده بيعمل timeout لأن Railway بيفتح بورت الـ HTTP بس على الدومين العام.

1. **Railway → New Service** من نفس ريبو `uip-laravel` (اسمه مثلًا `uip-reverb`).
   - Start Command: `php artisan reverb:start --host=0.0.0.0 --port=$PORT --no-interaction`
   - Generate Domain (هيديك `https://uip-reverb-production.up.railway.app`).
   - Variables: نفس `APP_KEY` + `REVERB_APP_ID` + `REVERB_APP_KEY` + `REVERB_APP_SECRET` (نفس القيم بالظبط على الخدمتين).
2. **خدمة الـ API (uip-laravel)** Variables:
   - `BROADCAST_CONNECTION=reverb`
   - `REVERB_APP_ID / KEY / SECRET` (نفس بتوع خدمة Reverb)
   - `REVERB_HOST=<دومين خدمة reverb بدون https://>` `REVERB_PORT=443` `REVERB_SCHEME=https`
3. **Vercel (الفرونت)** Variables ثم Redeploy:
   - `VITE_REVERB_APP_KEY=<نفس REVERB_APP_KEY>`
   - `VITE_REVERB_HOST=<دومين خدمة reverb>` `VITE_REVERB_PORT=443` `VITE_REVERB_SCHEME=https`
4. `php artisan config:clear` (أو redeploy للـ API).

بنفس الإعداد ده الاجتماعات (Connecting…) هتشتغل كمان لأنها بتستخدم نفس Reverb.

## للتأكد إنه شغال
افتح حسابين، ابعت رسالة من واحد: لازم تظهر عند التاني بدون ما يعمل أي حاجة. وفي DevTools → Network → WS لازم تلاقي اتصال `connected` على دومين Reverb.

## حدود (للأمانة)
- ده **ping ثم جلب** (رحلة HTTP واحدة)، يعني ~100–300ms حسب الشبكة والـ region — مش 0ms. لو عايز الرسالة نفسها تيجي جوه الـ socket (أسرع شوية) ممكن نضيفها.
- تعديل ملف في My PC: القايمة والملف المفتوح للعرض بيتحدّثوا لحظيًا عند الحفظ من جهاز تاني. **الكتابة الحرف بحرف** بين جهازين (زي Google Docs) محتاجة CRDT — مش داخلة هنا.
- الكود ما اتجربش على Railway حقيقي من عندي؛ فحصت syntax الفرونت بس، والـ PHP ما اتعملهوش lint (مفيش PHP في البيئة) — جرّبه على staging الأول.
